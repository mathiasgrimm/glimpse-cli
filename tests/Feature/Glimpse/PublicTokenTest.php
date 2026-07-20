<?php

use MathiasGrimm\GlimpseCli\Glimpse\Config;

test('the built-in public token is the last resort', function () {
    $config = new Config(publicTokenOverride: 'pub-token');

    expect($config->token())->toBe('pub-token')
        ->and($config->usingPublicToken())->toBeTrue();
});

test('the GLIMPSE_TOKEN env variable wins over the public token', function () {
    putenv('GLIMPSE_TOKEN=user-token');

    $config = new Config(publicTokenOverride: 'pub-token');

    expect($config->token())->toBe('user-token')
        ->and($config->usingPublicToken())->toBeFalse();
});

test('a stored token wins over the public token', function () {
    $config = new Config(publicTokenOverride: 'pub-token');
    $config->setToken('stored-token');

    expect($config->token())->toBe('stored-token')
        ->and($config->usingPublicToken())->toBeFalse();
});

test('an empty public token keeps the fallback off', function () {
    $config = new Config(publicTokenOverride: '');

    expect($config->token())->toBeNull()
        ->and($config->publicToken())->toBeNull()
        ->and($config->usingPublicToken())->toBeFalse();
});

test('the baked release token is a plausible Sanctum token when present', function () {
    $baked = (new Config)->publicToken();

    // Empty in the repository is allowed (the release guard enforces
    // baking); when present it must look like id|secret.
    if ($baked !== null) {
        expect($baked)->toMatch('/^\d+\|\w{40,}$/');
    }

    expect(true)->toBeTrue();
});
