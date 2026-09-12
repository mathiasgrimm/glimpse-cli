<?php

use MathiasGrimm\GlimpseCli\Commands\InitCommand;
use Symfony\Component\Process\Process;

test('the optimization workflow runs check --fix and preserves its exit status', function (int $status) {
    chdirWorkspace();
    preg_match('/^ +run: (cpx .+)$/m', InitCommand::OPTIMIZE_TEMPLATE, $matches);
    expect($matches[1])->toBe('cpx mathiasgrimm/glimpse-cli check . --fix');

    $executable = $this->configHome.'/cpx';
    file_put_contents($executable, '#!'.PHP_BINARY."\n".file_get_contents(base_path('tests/Fixtures/WorkflowGlimpse.php')));
    chmod($executable, 0755);

    $process = new Process(['bash', '-eo', 'pipefail', '-c', $matches[1]], workspace(), [
        'PATH' => $this->configHome.':'.getenv('PATH'),
        'WORKFLOW_CALLS' => $this->configHome.'/calls',
        'WORKFLOW_STATUS' => (string) $status,
        'GLIMPSE_TOKEN' => '',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe($status)
        ->and(json_decode(file_get_contents($this->configHome.'/calls'), true))
        ->toBe(['mathiasgrimm/glimpse-cli', 'check', '.', '--fix']);
})->with([0, 1, 2]);

test('the optimization workflow sets up cpx with PHP 8.5', function () {
    expect(InitCommand::OPTIMIZE_TEMPLATE)
        ->toContain("php-version: '8.5'", 'tools: cpx/cpx')
        ->not->toContain('composer global', 'jq ', '--quality=85');
});

test('the check-only workflow uses cpx and remains read-only', function () {
    expect(InitCommand::WORKFLOW_TEMPLATE)
        ->toContain("php-version: '8.5'", 'tools: cpx/cpx', 'run: cpx mathiasgrimm/glimpse-cli check .')
        ->not->toContain('--fix', 'composer global');
});
