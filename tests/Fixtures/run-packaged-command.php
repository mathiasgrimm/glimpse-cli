<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use Symfony\Component\Process\Process;

$settings = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
Phar::loadPhar($settings['phar'], 'integration.phar');
require 'phar://integration.phar/vendor/autoload.php';
$app = require 'phar://integration.phar/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(Config::class, new Config(publicTokenOverride: $settings['token']['token']));

Http::fake(function (Request $request) use ($settings, $argv) {
    $process = new Process([
        $settings['api_php'], __DIR__.'/run-local-api.php', $argv[1], 'request',
    ], $settings['api_root'], ['APP_ENV' => 'testing']);
    $process->setInput(json_encode([
        'path' => parse_url($request->url(), PHP_URL_PATH),
        'method' => $request->method(),
        'authorization' => $request->header('Authorization')[0],
        'body' => $request->body(),
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    return Http::response($response['body'], $response['status'], $response['headers']);
});

$options = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
$status = Artisan::call($argv[2], $options);
echo Artisan::output();
exit($status);
