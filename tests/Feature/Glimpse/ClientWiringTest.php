<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpsePhp\AuthException;
use MathiasGrimm\GlimpsePhp\Client;
use Tests\Fixtures\Images;

test('the container client resolves the env token on every request', function () {
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/info' => Http::response(['data' => []])]);

    app(Client::class)->info(Images::png());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.com/api/v1/info'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('GLIMPSE_API_URL points the container client at a different host', function () {
    putenv('GLIMPSE_TOKEN=test-token');
    putenv('GLIMPSE_API_URL=https://glimpseimg.test/api');
    Http::fake(['*/v1/info' => Http::response(['data' => []])]);

    app(Client::class)->info(Images::png());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.test/api/v1/info');
});

test('a missing token fails before any HTTP request', function () {
    Http::fake();

    expect(fn () => app(Client::class)->optimize(Images::png()))
        ->toThrow(AuthException::class, 'Not authenticated.');

    Http::assertNothingSent();
});

test('API requests identify the CLI and its running version', function (string $version) {
    config(['app.version' => $version]);
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake([
        '*/v1/info' => Http::response(['data' => []]),
        '*/user' => Http::response(['id' => 7, 'name' => 'Mathias', 'email' => 'mathias@example.com', 'created_at' => '2025-11-03T09:30:00.000000Z']),
    ]);

    $client = app(Client::class);
    $client->info(Images::png());
    $client->user();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.com/api/v1/info'
        && $request->hasHeader('User-Agent', 'glimpse-cli/'.$version));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.com/api/user'
        && $request->hasHeader('User-Agent', 'glimpse-cli/'.$version));
})->with(['v1.7.2', 'versioned-user-agent']);
