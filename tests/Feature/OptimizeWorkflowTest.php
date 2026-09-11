<?php

use MathiasGrimm\GlimpseCli\Commands\InitCommand;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Images;

/**
 * Execute the actual PHP embedded in the distributed workflow against a
 * disposable Git repository and a fake glimpse executable. No API or GitHub
 * writes are made; the assertions inspect what the PR action would receive.
 */
function workflowProcess(array $report, array $environment = []): Process
{
    $temp = test()->configHome;
    file_put_contents($temp.'/report.json', json_encode($report));
    preg_match("/php <<'PHP'\n(.*?)\n          PHP/s", InitCommand::OPTIMIZE_TEMPLATE, $matches);
    $script = preg_replace('/^          /m', '', $matches[1]);
    file_put_contents($temp.'/workflow.php', $script);
    file_put_contents($temp.'/glimpse', '#!'.PHP_BINARY."\n".file_get_contents(base_path('tests/Fixtures/WorkflowGlimpse.php')));
    chmod($temp.'/glimpse', 0755);

    return new Process([PHP_BINARY, $temp.'/workflow.php'], workspace(), array_merge([
        'PATH' => $temp.':'.getenv('PATH'),
        'GLIMPSE_TOKEN' => 'test-token',
        'BASE_BRANCH' => '12.x',
        'RUNNER_TEMP' => $temp,
        'GITHUB_OUTPUT' => $temp.'/outputs',
        'GITHUB_STEP_SUMMARY' => $temp.'/summary',
        'WORKFLOW_REPORT' => $temp.'/report.json',
        'WORKFLOW_CALLS' => $temp.'/calls',
        'WORKFLOW_STATUS' => $report['files'] === [] ? '0' : '1',
        'WORKFLOW_BEHAVIOR' => 'optimize',
        'WORKFLOW_IMAGE' => base64_encode(Images::png()),
    ], $environment));
}

function workflowReport(array $files): array
{
    return ['files' => array_map(fn (string $file): array => ['file' => $file], $files), 'failed' => [], 'needs_optimization' => count($files)];
}

beforeEach(function () {
    chdirWorkspace();
    createImage('photo.png', Images::png().'padding');
    $git = new Process(['git', 'init', '--quiet'], workspace());
    $git->mustRun();
    (new Process(['git', 'add', '--', 'photo.png'], workspace()))->mustRun();
    (new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'Fixture'], workspace()))->mustRun();
});

test('the workflow optimizes only reported images, stages them and records actual savings', function () {
    createImage('untouched.png');
    file_put_contents('unrelated.txt', 'keep out of the PR');
    $process = workflowProcess(workflowReport(['photo.png']));
    $process->mustRun();

    expect(file_get_contents('photo.png'))->toBe(Images::png())
        ->and(baselineFiles()['photo.png'])->toBe(baselineEntry(workspace().'/photo.png', 'optimize'))
        ->and(file_get_contents(test()->configHome.'/outputs'))->toContain('branch=automation/glimpse-'.hash('sha256', '12.x'))
        ->and(file_get_contents(test()->configHome.'/glimpse-pr.md'))->toContain('| 7 |');

    $staged = new Process(['git', 'diff', '--cached', '--name-only', '-z'], workspace());
    $staged->mustRun();
    expect(explode("\0", trim($staged->getOutput(), "\0")))->toBe(['.glimpse-baseline.json', 'photo.png']);
});

test('an empty report reconciles the existing PR without staging new changes', function () {
    $process = workflowProcess(workflowReport([]));
    $process->mustRun();
    expect(file_exists(baselinePath()))->toBeFalse()
        ->and(file_get_contents(test()->configHome.'/outputs'))->toContain('checked=true', 'branch=automation/glimpse-'.hash('sha256', '12.x'))
        ->and(file_get_contents(test()->configHome.'/calls'))->toBe("check\n");
});

test('the workflow preserves filenames and isolates destination branches', function (string $file, string $branch) {
    rename('photo.png', $file);
    (new Process(['git', '--literal-pathspecs', 'add', '--', $file], workspace()))->mustRun();
    $process = workflowProcess(workflowReport([$file]), ['BASE_BRANCH' => $branch]);
    $process->mustRun();

    expect(file_exists($file))->toBeTrue()
        ->and(scandir(workspace()))->toContain($file)
        ->and(file_get_contents(test()->configHome.'/outputs'))->toContain('branch=automation/glimpse-'.hash('sha256', $branch));
})->with([
    ['photo.jpeg', '12.x'],
    ['PHOTO.JPG', 'quattro'],
    ["a , [b]\nimage.png", 'release/12.x'],
    ['-leading.png', 'main'],
]);

test('check failures cannot become a partial optimization PR', function (array $report, string $status) {
    $process = workflowProcess($report, ['WORKFLOW_STATUS' => $status]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and(file_exists(test()->configHome.'/outputs'))->toBeFalse()
        ->and(file_get_contents('photo.png'))->toBe(Images::png().'padding');
})->with([
    'failed file' => [array_merge(workflowReport(['photo.png']), ['failed' => [['file' => 'bad.png', 'error' => 'Unreadable']]]), '1'],
    'wrong count' => [array_merge(workflowReport(['photo.png']), ['needs_optimization' => 2]), '1'],
    'wrong status' => [workflowReport(['photo.png']), '0'],
    'unexpected failure' => [workflowReport([]), '2'],
]);

test('unsafe or untracked report paths fail before any optimization', function (string $file) {
    createImage('untracked.png');
    symlink('photo.png', 'link.png');
    (new Process(['git', 'add', '--', 'link.png'], workspace()))->mustRun();
    $process = workflowProcess(workflowReport([$file]));
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and(file_get_contents(test()->configHome.'/calls'))->toBe("check\n")
        ->and(file_exists(test()->configHome.'/outputs'))->toBeFalse();
})->with(['untracked.png', 'link.png', '../photo.png', '/tmp/photo.png', ':(glob)*']);

test('transform and baseline failures prevent publishing', function (string $behavior) {
    $process = workflowProcess(workflowReport(['photo.png']), ['WORKFLOW_BEHAVIOR' => $behavior]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and(file_exists(test()->configHome.'/outputs'))->toBeFalse();
})->with(['failure', 'larger', 'wrong-format', 'missing-baseline', 'wrong-hash']);

test('zero-saving success still records the image and stops repeat transforms', function () {
    $process = workflowProcess(workflowReport(['photo.png']), ['WORKFLOW_BEHAVIOR' => 'unchanged']);
    $process->mustRun();
    expect(baselineFiles()['photo.png'])->toBe(baselineEntry(workspace().'/photo.png', 'optimize'));

    // The fake check uses real file hashes to skip successful baseline entries.
    unlink(test()->configHome.'/outputs');
    $again = workflowProcess(workflowReport(['photo.png']), ['WORKFLOW_BEHAVIOR' => 'unchanged']);
    $again->mustRun();
    expect(file_get_contents(test()->configHome.'/calls'))->toBe("check\noptimize\ncheck\n");
});

test('a private token and regular baseline are required', function (bool $symlink) {
    file_put_contents(test()->configHome.'/outside.json', '{"files":{}}');
    if ($symlink) {
        symlink(test()->configHome.'/outside.json', baselinePath());
    }
    $process = workflowProcess(workflowReport(['photo.png']), ['GLIMPSE_TOKEN' => $symlink ? 'token' : '']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and(file_exists(test()->configHome.'/calls'))->toBeFalse()
        ->and(file_get_contents(test()->configHome.'/outside.json'))->toBe('{"files":{}}');
})->with([false, true]);

test('the PR action reconciles successful empty reports and removes obsolete automation branches', function () {
    expect(InitCommand::OPTIMIZE_TEMPLATE)
        ->toContain("if: steps.images.outputs.checked == 'true'")
        ->toContain('delete-branch: true')
        ->toContain('add-paths: .glimpse-baseline.json');
});
