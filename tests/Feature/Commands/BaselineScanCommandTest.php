<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * The baseline behavior analyze and check share: both scans run through
 * the same partition logic, so these contract tests run against each
 * command, asserting exit codes and API traffic. Command-specific output,
 * JSON shapes, and --update-baseline semantics stay in the per-command
 * test files.
 */
beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);
});

test('passes without touching the API when the baseline covers every image', function (string $command) {
    chdirWorkspace();
    writeBaseline(['photo.png' => baselineEntry(createImage('photo.png'))]);

    expect(Artisan::call($command, ['input' => workspace()]))->toBe(0)
        ->and(Artisan::output())->toContain('The 1 image is covered by the baseline.');

    Http::assertNothingSent();
})->with(['analyze', 'check']);

test('a baselined file whose content changed re-enters the scan', function (string $command, int $exitCode) {
    chdirWorkspace();
    $path = createImage('photo.png');
    $entry = baselineEntry($path);
    $entry['xxh128'] = 'stale';
    writeBaseline(['photo.png' => $entry]);

    expect(Artisan::call($command, ['input' => workspace()]))->toBe($exitCode);

    Http::assertSentCount(1);
})->with([
    'analyze reports it again' => ['analyze', 0],
    'check flags the offender again' => ['check', 1],
]);

test('an explicit single file is processed even when baselined', function (string $command, int $exitCode) {
    chdirWorkspace();
    $path = createImage('photo.png');
    writeBaseline(['photo.png' => baselineEntry($path)]);

    expect(Artisan::call($command, ['input' => $path]))->toBe($exitCode);

    Http::assertSentCount(1);
})->with([
    'analyze processes it' => ['analyze', 0],
    'check flags the offender' => ['check', 1],
]);

test('a malformed baseline fails loudly before any HTTP request', function (string $command) {
    chdirWorkspace();
    createImage('photo.png');
    writeMalformedBaseline();

    $this->artisan($command, ['input' => workspace()])
        ->expectsOutputToContain('Malformed')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['analyze', 'check']);
