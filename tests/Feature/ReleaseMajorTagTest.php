<?php

use Symfony\Component\Process\Process;

test('stable releases advance only their major alias and prereleases leave aliases unchanged', function () {
    chdirWorkspace();
    $git = function (array $arguments): string {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$arguments], workspace());
        $process->mustRun();

        return trim($process->getOutput());
    };
    $remote = $this->configHome.'/remote.git';
    $git(['init', '--bare', $remote]);
    $git(['init', '-b', 'main']);
    $git(['remote', 'add', 'origin', $remote]);
    copy(base_path('Makefile'), workspace().'/Makefile');
    $aliases = [];

    foreach (['v1.8.0', 'v1.8.1', 'v2.0.0', 'v2.1.0-rc.1'] as $version) {
        $git(['commit', '--allow-empty', '-qm', $version]);
        $sha = $git(['rev-parse', 'HEAD']);
        $git(['tag', $version]);
        $git(['push', 'origin', 'refs/tags/'.$version]);
        $process = new Process(['make', 'update-major-tag', 'VERSION='.$version], workspace());
        $process->mustRun();

        if (! str_contains($version, '-')) {
            $aliases[explode('.', $version)[0]] = $sha;
        }
        foreach ($aliases as $alias => $expected) {
            expect($git(['--git-dir='.$remote, 'rev-parse', 'refs/tags/'.$alias]))->toBe($expected);
        }
        expect($git(['--git-dir='.$remote, 'rev-parse', 'refs/tags/'.$version]))->toBe($sha);
    }
});
