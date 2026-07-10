<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/usage' => Http::response(['data' => [
        'period' => [
            'from' => '2026-07-01T00:00:00+00:00',
            'to' => '2026-07-31T23:59:59+00:00',
        ],
        'operations' => 68,
        'bytes_saved' => 62111321,
        'average_reduction' => 45,
        'by_operation' => [
            'convert' => 22,
            'optimize' => 18,
            'resize' => 7,
            'thumbnail' => 8,
            'info' => 6,
            'analyze' => 7,
        ],
    ]])]);
});

test('prints a pretty usage table by default', function () {
    $this->artisan('usage')
        ->expectsOutputToContain('2026-07-01 to 2026-07-31')
        ->expectsOutputToContain('59.2 MB')
        ->expectsOutputToContain('45%')
        ->expectsOutputToContain('convert: 22')
        ->expectsOutputToContain('analyze: 7')
        ->assertExitCode(0);
});

test('prints the raw usage summary with --json', function () {
    $exitCode = Artisan::call('usage', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['operations'])->toBe(68)
        ->and($decoded['bytes_saved'])->toBe(62111321)
        ->and($decoded['by_operation']['convert'])->toBe(22)
        ->and($decoded['period']['from'])->toBe('2026-07-01T00:00:00+00:00');
});

test('fails cleanly without a token', function () {
    putenv('GLIMPSE_TOKEN');

    $this->artisan('usage')
        ->expectsOutputToContain('Not authenticated. Run: glimpse auth')
        ->assertExitCode(1);
});
