<?php

use Symfony\Component\Process\Process;

/**
 * Bridge the CLI HTTP transport into a separate local API kernel. Both
 * applications keep their own autoloaders, and all data uses a temporary DB.
 */
test('the packaged CLI talks to the local API with public image access', function () {
    $apiRoot = getenv('GLIMPSE_API_CHECKOUT');
    $phar = getenv('GLIMPSE_TEST_PHAR');
    $apiPhp = getenv('GLIMPSE_API_PHP') ?: PHP_BINARY;
    if (! is_string($apiRoot) || ! is_string($phar)) {
        test()->markTestSkipped('Set GLIMPSE_API_CHECKOUT and GLIMPSE_TEST_PHAR for local cross-repository integration.');
    }
    $directory = sys_get_temp_dir().'/glimpse-integration-'.bin2hex(random_bytes(8));
    mkdir($directory);
    touch($directory.'/database.sqlite');
    $key = '{glimpse-integration-'.bin2hex(random_bytes(8)).'}';
    $settings = var_export([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $directory.'/database.sqlite',
        'database.connections.sqlite.url' => null,
        'glimpse.shared_token_ids' => [],
        'glimpse.public_processing.enabled' => true,
        'glimpse.public_processing.key' => $key,
        'nightwatch.enabled' => false,
        'cache.default' => 'array',
        'session.driver' => 'array',
    ], true);
    $bootstrap = 'require '.var_export($apiRoot.'/vendor/autoload.php', true).'; '
        .'$app = require '.var_export($apiRoot.'/bootstrap/app.php', true).'; '
        .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config()->set('.$settings.'); ';
    $setup = $bootstrap
        .'Illuminate\\Support\\Facades\\Artisan::call("migrate", ["--force" => true]); '
        .'$token = app(App\\Actions\\ApiToken\\ProvisionSharedApiToken::class)->handle(); '
        .'echo json_encode(["id" => $token->accessToken->id, "token" => $token->plainTextToken]);';
    $provision = new Process([$apiPhp, '-r', $setup], $apiRoot, ['APP_ENV' => 'testing']);
    $provision->mustRun();
    $token = json_decode($provision->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $bridge = $bootstrap.'config()->set("glimpse.shared_token_ids", ['.$token['id'].']); '
        .'$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); '
        .'$request = Illuminate\\Http\\Request::create($input["path"], $input["method"], [], [], [], '
        .'["CONTENT_TYPE" => "application/json", "HTTP_ACCEPT" => "application/json", "HTTP_AUTHORIZATION" => $input["authorization"]], $input["body"]); '
        .'$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class); $response = $kernel->handle($request); '
        .'$kernel->terminate($request, $response); echo json_encode(["body" => $response->getContent(), "status" => $response->getStatusCode(), "headers" => $response->headers->all()]);';
    file_put_contents($directory.'/api.php', '<?php '.$bridge);
    copy($apiRoot.'/tests/Fixtures/Images/small-translucent-reflex.png', $directory.'/input.png');
    $runner = <<<'SCRIPT'
Phar::loadPhar(PHAR_PATH, 'integration.phar');
require 'phar://integration.phar/vendor/autoload.php';
$app = require 'phar://integration.phar/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->instance(MathiasGrimm\GlimpseCli\Glimpse\Config::class, new MathiasGrimm\GlimpseCli\Glimpse\Config(publicTokenOverride: PUBLIC_TOKEN));
Illuminate\Support\Facades\Http::fake(function ($request) {
    $process = new Symfony\Component\Process\Process([API_PHP, BRIDGE_PATH], API_ROOT, ['APP_ENV' => 'testing']);
    $process->setInput(json_encode([
        'path' => parse_url($request->url(), PHP_URL_PATH), 'method' => $request->method(),
        'authorization' => $request->header('Authorization')[0], 'body' => $request->body(),
    ]));
    $process->mustRun();
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    return Illuminate\Support\Facades\Http::response($response['body'], $response['status'], $response['headers']);
});
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$status = Illuminate\Support\Facades\Artisan::call($argv[1], $options);
echo Illuminate\Support\Facades\Artisan::output();
exit($status);
SCRIPT;
    $runner = strtr($runner, [
        'API_PHP' => var_export($apiPhp, true), 'PHAR_PATH' => var_export($phar, true), 'PUBLIC_TOKEN' => var_export($token['token'], true),
        'BRIDGE_PATH' => var_export($directory.'/api.php', true), 'API_ROOT' => var_export($apiRoot, true),
    ]);
    try {
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
            $process = new Process([PHP_BINARY, '-r', $runner, $operation, json_encode($options)], $directory, ['GLIMPSE_TOKEN' => false, 'XDG_CONFIG_HOME' => $directory]);
            $process->run();
            expect($process->getExitCode())->toBe(0, $operation.': '.$process->getErrorOutput().$process->getOutput());
            expect(json_decode($process->getOutput(), true))->toBeArray();
        }
        $usage = new Process([PHP_BINARY, '-r', $runner, 'usage', '{"--json":true}'], $directory, ['GLIMPSE_TOKEN' => false, 'XDG_CONFIG_HOME' => $directory]);
        $usage->run();
        expect($usage->getExitCode())->toBe(1)
            ->and($usage->getOutput())->toBe('')
            ->and($usage->getErrorOutput())->toContain('Personal usage requires your own token.');
    } finally {
        $cleanup = $bootstrap.'$redis = Illuminate\\Support\\Facades\\Redis::connection("public_processing"); '
            .'$key = config("glimpse.public_processing.key"); '
            .'$redis->del($key.":global:minute", $key.":global:day", $key.":ip:".hash("sha256", "127.0.0.1").":minute", $key.":ip:".hash("sha256", "127.0.0.1").":day");';
        (new Process([$apiPhp, '-r', $cleanup], $apiRoot))->mustRun();
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
