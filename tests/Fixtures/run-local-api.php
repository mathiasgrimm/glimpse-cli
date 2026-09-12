<?php

use App\Actions\ApiToken\ProvisionSharedApiToken;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

$settings = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
require $settings['api_root'].'/vendor/autoload.php';
$app = require $settings['api_root'].'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
config()->set([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $settings['database'],
    'database.connections.sqlite.url' => null,
    'glimpse.shared_token_ids' => isset($settings['token']) ? [$settings['token']['id']] : [],
    'glimpse.public_processing.enabled' => true,
    'nightwatch.enabled' => false,
    'cache.default' => 'array',
    'session.driver' => 'array',
]);

if ($argv[2] === 'setup') {
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    $token = app(ProvisionSharedApiToken::class)->handle();
    echo json_encode(['id' => $token->accessToken->id, 'token' => $token->plainTextToken], JSON_THROW_ON_ERROR);
    exit(0);
}

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$request = Request::create($input['path'], $input['method'], server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => $input['authorization'],
], content: $input['body']);
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo json_encode([
    'body' => $response->getContent(),
    'status' => $response->getStatusCode(),
    'headers' => $response->headers->all(),
], JSON_THROW_ON_ERROR);
