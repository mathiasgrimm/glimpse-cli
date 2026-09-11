<?php

use MathiasGrimm\GlimpseCli\Commands\InitCommand;
use Symfony\Component\Process\Process;

/**
 * Run the generated Bash step with a fake CLI to test command wiring and failures.
 */
function workflowProcess(array $report, array $environment = []): Process
{
    $temp = test()->configHome;
    file_put_contents($temp.'/report.json', json_encode($report));
    preg_match('/run: \|\n( +# Check exits.*?)(?=\n\n)/s', InitCommand::OPTIMIZE_TEMPLATE, $matches);
    file_put_contents($temp.'/workflow.sh', $matches[1]);
    file_put_contents($temp.'/glimpse', '#!'.PHP_BINARY."\n".file_get_contents(base_path('tests/Fixtures/WorkflowGlimpse.php')));
    chmod($temp.'/glimpse', 0755);

    return new Process(['bash', '--noprofile', '--norc', '-eo', 'pipefail', $temp.'/workflow.sh'], workspace(), array_merge([
        'PATH' => $temp.':'.getenv('PATH'),
        'RUNNER_TEMP' => $temp,
        'WORKFLOW_REPORT' => $temp.'/report.json',
        'WORKFLOW_CALLS' => $temp.'/calls',
        'WORKFLOW_STATUS' => $report['files'] === [] ? '0' : '1',
        'WORKFLOW_OPTIMIZE_STATUS' => '0',
    ], $environment));
}

function workflowReport(array $files): array
{
    return ['files' => array_map(fn (string $file): array => ['file' => $file], $files), 'failed' => [], 'needs_optimization' => count($files)];
}

function workflowCalls(): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true),
        file(test()->configHome.'/calls', FILE_IGNORE_NEW_LINES),
    );
}

beforeEach(function () {
    chdirWorkspace();
});

test('the workflow passes only reported filenames to optimize with the same output path', function () {
    $files = ['photo.jpeg', 'PHOTO.JPG', "a , [b]\nimage.png", '-leading.png', 'a "quote" and $dollar.png'];
    workflowProcess(workflowReport($files))->mustRun();

    expect(workflowCalls())->toBe([
        ['check', '.', '--json'],
        ...array_map(fn (string $file): array => ['optimize', './'.$file, '--quality=85', '--output=./'.$file, '--force'], $files),
    ]);
});

test('an empty report succeeds without calling optimize', function () {
    workflowProcess(workflowReport([]))->mustRun();

    expect(workflowCalls())->toBe([['check', '.', '--json']]);
});

test('checking errors stop the job before optimization', function (array $report, string $status) {
    $process = workflowProcess($report, ['WORKFLOW_STATUS' => $status]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and(workflowCalls())->toBe([['check', '.', '--json']]);
})->with([
    'failed file' => [array_merge(workflowReport(['photo.png']), ['failed' => [['file' => 'bad.png', 'error' => 'Unreadable']]]), '1'],
    'command failure' => [workflowReport([]), '2'],
]);

test('an optimize failure stops the loop and fails the job', function () {
    $process = workflowProcess(workflowReport(['first.png', 'second.png']), ['WORKFLOW_OPTIMIZE_STATUS' => '1']);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and(workflowCalls())->toBe([
            ['check', '.', '--json'],
            ['optimize', './first.png', '--quality=85', '--output=./first.png', '--force'],
        ]);
});
