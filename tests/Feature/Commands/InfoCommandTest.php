<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/info' => Http::response(['data' => fullInfoApiResponse()])]);
});

test('prints a pretty metadata table by default', function () {
    $this->artisan('info', ['input' => createImage()])
        ->expectsOutputToContain('PNG')
        ->expectsOutputToContain('1280 x 720 px')
        ->expectsOutputToContain('72 x 72 PIXELS_PER_INCH')
        ->expectsOutputToContain('ZIP (quality 92)')
        ->expectsOutputToContain('exif:Make: Canon')
        ->assertExitCode(0);
});

test('the --json output mirrors the API response byte for byte', function () {
    $exitCode = Artisan::call('info', ['input' => createImage(), '--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe(json_encode(fullInfoApiResponse(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
});
