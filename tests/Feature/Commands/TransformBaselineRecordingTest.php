<?php

/**
 * The recording contract every transform command honors through the shared
 * UpdatesBaseline trait: a successful write records the expected entries,
 * keyed relative to the CWD and stamped with the operation that wrote
 * them, and never creates the baseline file. The deeper cases (ignore
 * rules, locking, malformed files, outside-CWD outputs, in-place writes)
 * live in ConvertCommandTest and OptimizeCommandTest as representative
 * coverage of the trait.
 */
beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('records its outputs in an existing baseline', function (string $command, array $arguments, string $format, array $expected) {
    chdirWorkspace();
    fakeTransform($command, $format);

    $input = createImage('photo.png');
    writeBaseline();

    $this->artisan($command, ['input' => $input] + $arguments)->assertExitCode(0);

    $files = [];

    foreach ($expected as $key => [$basename, $via]) {
        $files[$key] = baselineEntry(dirname($input).'/'.$basename, $via);
    }

    expect(baselineFiles())->toBe($files);
})->with([
    'convert records source and output' => ['convert', ['--format' => 'webp'], 'webp', [
        'photo.png' => ['photo.png', 'convert'],
        'photo.webp' => ['photo.webp', 'convert'],
    ]],
    'optimize records source and output' => ['optimize', [], 'jpg', [
        'photo.optimized.jpg' => ['photo.optimized.jpg', 'optimize'],
        'photo.png' => ['photo.png', 'optimize'],
    ]],
    'resize records only its output' => ['resize', ['--width' => '800'], 'jpg', [
        'photo.resized.jpg' => ['photo.resized.jpg', 'resize'],
    ]],
    'thumbnail records only its output' => ['thumbnail', [], 'jpg', [
        'photo.thumb.jpg' => ['photo.thumb.jpg', 'thumbnail'],
    ]],
]);

test('creates no baseline when none exists', function (string $command, array $arguments, string $format) {
    chdirWorkspace();
    fakeTransform($command, $format);

    $input = createImage('photo.png');

    $this->artisan($command, ['input' => $input] + $arguments)->assertExitCode(0);

    expect(file_exists(baselinePath()))->toBeFalse();
})->with([
    'convert' => ['convert', ['--format' => 'webp'], 'webp'],
    'optimize' => ['optimize', [], 'jpg'],
    'resize' => ['resize', ['--width' => '800'], 'jpg'],
    'thumbnail' => ['thumbnail', [], 'jpg'],
]);
