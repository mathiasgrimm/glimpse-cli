<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
});

test('image commands call the API with the built-in public token', function (string $command, array $options) {
    if ($command === 'info') {
        Http::fake(['*/v1/info' => Http::response(['data' => fullInfoApiResponse()])]);
    } else {
        fakeTransform($command);
    }

    expect(Artisan::call($command, ['input' => createImage(), ...$options]))->toBe(0);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer pub-token'));
})->with([
    ['optimize', []], ['resize', ['--width' => 6]], ['thumbnail', []], ['info', []],
]);
