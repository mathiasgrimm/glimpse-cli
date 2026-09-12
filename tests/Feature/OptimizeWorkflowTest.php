<?php

use MathiasGrimm\GlimpseCli\Commands\InitCommand;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Images;

test('the optimization workflow runs check --fix and preserves its exit status', function (int $status, string $quality, string $threshold) {
    chdirWorkspace();
    $workflow = file_get_contents(base_path('.github/workflows/optimize-images.yml'));
    preg_match('/run: \|\n((?: +[^\n]*\n)+)/', $workflow, $matches);

    $executable = $this->configHome.'/cpx';
    file_put_contents($executable, '#!'.PHP_BINARY."\n".file_get_contents(base_path('tests/Fixtures/WorkflowGlimpse.php')));
    chmod($executable, 0755);

    $process = new Process(['bash', '-eo', 'pipefail', '-c', $matches[1]], workspace(), [
        'PATH' => $this->configHome.':'.getenv('PATH'),
        'WORKFLOW_CALLS' => $this->configHome.'/calls',
        'WORKFLOW_STATUS' => (string) $status,
        'GLIMPSE_TOKEN' => '',
        'GLIMPSE_QUALITY' => $quality,
        'GLIMPSE_THRESHOLD' => $threshold,
    ]);
    $process->run();

    $expected = ['--skip-local', 'mathiasgrimm/glimpse-cli', 'check', '.', '--fix', '--threshold='.$threshold];
    if ($quality !== '') {
        $expected[] = '--quality='.$quality;
    }

    expect($process->getExitCode())->toBe($status)
        ->and(json_decode(file_get_contents($this->configHome.'/calls'), true))
        ->toBe($expected)
        ->and(file_exists(workspace().'/unsafe'))->toBeFalse();
})->with([0, 1, 2])->with([['', '10'], ['85', '25.5'], ['85; touch unsafe', '10']]);

test('the optimization workflow sets up cpx with PHP 8.5', function () {
    expect(file_get_contents(base_path('.github/workflows/optimize-images.yml')))
        ->toContain("php-version: '8.5'", 'tools: cpx/cpx')
        ->not->toContain('composer global', 'jq ', '--quality=85');
});

test('the check-only workflow uses cpx and remains read-only', function () {
    expect(InitCommand::WORKFLOW_TEMPLATE)
        ->toContain("php-version: '8.5'", 'tools: cpx/cpx', 'run: cpx mathiasgrimm/glimpse-cli check .')
        ->not->toContain('--fix', 'composer global');
});

test('the comment handler validates the writer PR source and image before checkout', function (string $scenario, bool $allowed) {
    preg_match('/script: \|\n((?: +[^\n]*\n)+)/', file_get_contents(base_path('.github/workflows/skip-image.yml')), $matches);
    $script = $matches[1];
    $harness = <<<'JS'
const scenario = process.argv[1];
const sha = 'a'.repeat(40);
const source = 'b'.repeat(40);
const pr = { state: 'open', user: { login: 'github-actions[bot]' }, head: { sha, ref: 'automation/glimpse-main', repo: { full_name: 'owner/repo' } }, base: { ref: 'main' }, body: `<!-- glimpse-source: ${source} -->` };
const comment = { user: { login: 'writer' }, commit_id: sha, path: 'images/a space.png' };
let permission = 'write';
if (scenario === 'reader') permission = 'read';
if (scenario === 'closed') pr.state = 'closed';
if (scenario === 'fork') pr.head.repo.full_name = 'fork/repo';
if (scenario === 'manual') pr.user.login = 'writer';
if (scenario === 'branch') pr.head.ref = 'feature';
if (scenario === 'stale') comment.commit_id = 'c'.repeat(40);
if (scenario === 'source') pr.body = '';
if (scenario === 'traversal') comment.path = '../photo.png';
if (scenario === 'absolute') comment.path = '/photo.png';
if (scenario === 'code') comment.path = '.github/workflows/test.yml';
if (scenario === 'newline') comment.path = 'images/a\n$(echo unsafe).png';
const context = { repo: { owner: 'owner', repo: 'repo' }, issue: { number: 1 }, payload: { comment } };
const github = { rest: { pulls: { get: async () => ({ data: pr }) }, repos: { getCollaboratorPermissionLevel: async () => ({ data: { permission } }) } } };
const outputs = {};
const core = { setOutput: (key, value) => outputs[key] = value, exportVariable: (key, value) => outputs[key] = value };
JS;
    $process = new Process(['node', '--input-type=module', '-e', $harness."\nawait (async () => {\n".$script."\n})();\nprocess.stdout.write(JSON.stringify(outputs));", $scenario]);
    $process->run();

    expect($process->isSuccessful())->toBe($allowed, $process->getErrorOutput());
    if ($allowed) {
        $outputs = json_decode($process->getOutput(), true);
        expect($outputs['GLIMPSE_SKIP_SOURCE'])->toBe(str_repeat('b', 40))
            ->and($outputs['GLIMPSE_SKIP_HEAD'])->toBe(str_repeat('a', 40))
            ->and($outputs['GLIMPSE_SKIP_PATH'])->toBe($scenario === 'newline' ? "images/a\n$(echo unsafe).png" : 'images/a space.png');
    } else {
        expect($process->getOutput())->toBe('');
    }
})->with([
    ['valid', true], ['newline', true], ['reader', false], ['closed', false],
    ['fork', false], ['manual', false], ['branch', false], ['stale', false],
    ['source', false], ['traversal', false], ['absolute', false], ['code', false],
]);

test('the restore step commits only the selected image and baseline and refuses a changed remote head', function (bool $stale) {
    chdirWorkspace();
    $git = function (array $arguments): string {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$arguments], workspace());
        $process->mustRun();

        return trim($process->getOutput());
    };
    $remote = $this->configHome.'/remote.git';
    $git(['init', '--bare', $remote]);
    $git(['init', '-b', 'automation/glimpse-main']);
    $name = "images/a space\n\$(echo unsafe).png";
    mkdir(workspace().'/images');
    file_put_contents(workspace().'/'.$name, Images::png());
    $git(['add', '--', $name]);
    $git(['commit', '-qm', 'Original']);
    $source = $git(['rev-parse', 'HEAD']);
    file_put_contents(workspace().'/'.$name, Images::jpg());
    $git(['add', '--', $name]);
    $git(['commit', '-qm', 'Optimized']);
    $head = $git(['rev-parse', 'HEAD']);
    $git(['remote', 'add', 'origin', $remote]);
    $git(['push', '-u', 'origin', 'HEAD']);
    if ($stale) {
        file_put_contents(workspace().'/new.txt', 'A newer edit');
        $git(['add', 'new.txt']);
        $git(['commit', '-qm', 'Newer edit']);
        $git(['push', 'origin', 'HEAD']);
    }
    $remoteHead = $git(['--git-dir='.$remote, 'rev-parse', 'refs/heads/automation/glimpse-main']);
    $git(['checkout', '--detach', $head]);
    file_put_contents(workspace().'/unrelated.txt', 'Do not commit');

    $executable = $this->configHome.'/cpx';
    file_put_contents($executable, "#!/bin/bash\nshift 2\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('glimpse')).' "$@"'."\n");
    chmod($executable, 0755);
    preg_match('/name: Restore the image and commit its skip entry\n +shell: bash\n +run: \|\n((?: +[^\n]*\n)+)/', file_get_contents(base_path('.github/workflows/skip-image.yml')), $matches);
    $process = new Process(['bash', '-eo', 'pipefail', '-c', $matches[1]], workspace(), [
        'PATH' => $this->configHome.':'.getenv('PATH'),
        'GLIMPSE_SKIP_SOURCE' => $source,
        'GLIMPSE_SKIP_HEAD' => $head,
        'GLIMPSE_SKIP_BRANCH' => 'automation/glimpse-main',
        'GLIMPSE_SKIP_PATH' => $name,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBe(! $stale, $process->getErrorOutput())
        ->and(file_get_contents(workspace().'/'.$name))->toBe(Images::png())
        ->and($git(['diff-tree', '--no-commit-id', '--name-only', '-r', '-z', 'HEAD']))
        ->toBe('.glimpse-baseline.json'."\0".$name)
        ->and(file_exists(workspace().'/unsafe'))->toBeFalse();
    $updatedHead = $git(['--git-dir='.$remote, 'rev-parse', 'refs/heads/automation/glimpse-main']);
    expect($updatedHead)->toBe($stale ? $remoteHead : $git(['rev-parse', 'HEAD']));
})->with([false, true]);

test('the init template calls the published reusable workflows without embedding their steps', function () {
    preg_match_all('~uses: mathiasgrimm/glimpse-cli/(\.github/workflows/[^@]+)@([^\s]+)~', InitCommand::OPTIMIZE_TEMPLATE, $matches, PREG_SET_ORDER);
    expect($matches)->toHaveCount(2);
    foreach ($matches as $match) {
        expect(file_get_contents(base_path($match[1])))->toContain('workflow_call:')
            ->and($match[2])->toBe('v1.8.0');
    }
    expect(InitCommand::OPTIMIZE_TEMPLATE)->not->toContain('runs-on:', 'steps:', 'script:', 'run:');
});
