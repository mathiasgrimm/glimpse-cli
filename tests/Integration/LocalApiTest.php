<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

test('the packaged CLI talks to the local API with public image access', function () {
    $apiRoot = getenv('GLIMPSE_API_CHECKOUT');
    $phar = getenv('GLIMPSE_TEST_PHAR');
    if (! is_string($apiRoot) || ! is_string($phar)) {
        test()->markTestSkipped('Set GLIMPSE_API_CHECKOUT and GLIMPSE_TEST_PHAR for local cross-repository integration.');
    }

    $fixtures = dirname(__DIR__).'/Fixtures';
    $directory = sys_get_temp_dir().'/glimpse-integration-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($directory);
    $settingsPath = $directory.'/settings.json';
    $settings = [
        'api_root' => $apiRoot,
        'api_php' => getenv('GLIMPSE_API_PHP') ?: PHP_BINARY,
        'phar' => $phar,
        'database' => $directory.'/database.sqlite',
    ];

    try {
        touch($settings['database']);
        $filesystem->put($settingsPath, json_encode($settings, JSON_THROW_ON_ERROR));
        $setup = new Process([$settings['api_php'], $fixtures.'/run-local-api.php', $settingsPath, 'setup'], $apiRoot, ['APP_ENV' => 'testing']);
        $setup->mustRun();
        $settings['token'] = json_decode($setup->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $filesystem->put($settingsPath, json_encode($settings, JSON_THROW_ON_ERROR));
        $filesystem->copy($apiRoot.'/tests/Fixtures/Images/small-translucent-reflex.png', $directory.'/input.png');

        $run = function (string $command, array $options) use ($fixtures, $directory, $settingsPath): Process {
            $process = new Process([
                PHP_BINARY, $fixtures.'/run-packaged-command.php', $settingsPath, $command,
                json_encode($options, JSON_THROW_ON_ERROR),
            ], $directory, ['GLIMPSE_TOKEN' => false, 'GLIMPSE_API_URL' => false, 'XDG_CONFIG_HOME' => $directory]);
            $process->run();

            return $process;
        };

        foreach (['analyze', 'info', 'convert', 'optimize', 'resize', 'thumbnail'] as $operation) {
            $options = ['input' => $directory.'/input.png', '--json' => true];
            if ($operation === 'convert') {
                $options['--format'] = 'webp';
            }
            if ($operation === 'resize') {
                $options['--width'] = 6;
            }
            if (in_array($operation, ['convert', 'optimize', 'resize', 'thumbnail'], true)) {
                $options['--output'] = $directory.'/output-'.$operation.'.png';
            }
            $process = $run($operation, $options);
            expect($process->getExitCode())->toBe(0, $operation.': '.$process->getErrorOutput().$process->getOutput());
            expect(json_decode($process->getOutput(), true))->toBeArray();
        }

        $usage = $run('usage', ['--json' => true]);
        expect($usage->getExitCode())->toBe(1)
            ->and($usage->getOutput())->toBe('')
            ->and($usage->getErrorOutput())->toContain('Personal usage requires your own token.');
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});
