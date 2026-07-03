<?php

use App\Glimpse\Config;
use Illuminate\Support\Facades\Http;

test('reports when not authenticated', function () {
    Http::fake();

    $this->artisan('auth:status')
        ->expectsOutputToContain('Not authenticated. Run: glimpse auth')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('shows the API url, a masked token, and the identity', function () {
    app(Config::class)->setToken('secret-token-1234');

    Http::fake(['*/user' => Http::response(['name' => 'Mathias', 'email' => 'mathias@example.com'])]);

    $this->artisan('auth:status')
        ->expectsOutputToContain('API URL: https://glimpseimg.com/api')
        ->expectsOutputToContain('Token:   secr...1234')
        ->expectsOutputToContain('Authenticated as Mathias (mathias@example.com)')
        ->assertExitCode(0);
});

test('an invalid stored token surfaces the auth hint', function () {
    app(Config::class)->setToken('stale-token-9999');

    Http::fake(['*/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('auth:status')
        ->expectsOutputToContain('Invalid or missing token. Run: glimpse auth')
        ->assertExitCode(1);
});
