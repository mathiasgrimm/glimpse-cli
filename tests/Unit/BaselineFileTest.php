<?php

use App\Support\BaselineFile;
use GlimpseImg\ApiException;

test('a missing or empty file is an empty baseline', function () {
    $path = createImage('photo.png');

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse()
        ->and(BaselineFile::load(workspace())->count())->toBe(0);

    file_put_contents(workspace().'/'.BaselineFile::FILENAME, '');

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('skips a file whose path, size, and hash all match', function () {
    $path = createImage('photos/a.png');
    writeBaseline(['photos/a.png' => baselineEntry($path)]);

    expect(BaselineFile::load(workspace())->skips('photos/a.png', $path))->toBeTrue();
});

test('does not skip when the size differs', function () {
    $path = createImage('photo.png');
    $entry = baselineEntry($path);
    $entry['size'] += 1;

    writeBaseline(['photo.png' => $entry]);

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('does not skip when the size matches but the content changed', function () {
    $path = createImage('photo.png');
    $entry = baselineEntry($path);

    file_put_contents($path, str_repeat('x', $entry['size']));
    writeBaseline(['photo.png' => $entry]);

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('does not skip an unknown path', function () {
    $known = createImage('known.png');
    $other = createImage('other.png');

    writeBaseline(['known.png' => baselineEntry($known)]);

    expect(BaselineFile::load(workspace())->skips('other.png', $other))->toBeFalse();
});

test('record adds a new entry and refreshes a stale one', function () {
    $path = createImage('photo.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photo.png', $path);

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('photo.png', $path))->toBeTrue();

    file_put_contents($path, 'different content entirely');

    expect($baseline->skips('photo.png', $path))->toBeFalse();

    $baseline->record('photo.png', $path);

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('photo.png', $path))->toBeTrue();
});

test('prune drops entries for deleted files and keeps live ones', function () {
    $kept = createImage('kept.png');
    $gone = createImage('gone.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('kept.png', $kept);
    $baseline->record('gone.png', $gone);

    unlink($gone);
    $baseline->prune(workspace());

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('kept.png', $kept))->toBeTrue();
});

test('save writes pretty json with sorted keys and a trailing newline', function () {
    $b = createImage('b.png');
    $a = createImage('a.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('b.png', $b);
    $baseline->record('a.png', $a);
    $baseline->save(workspace());

    $size = filesize($a);
    $hash = hash_file('xxh128', $a);

    $expected = <<<JSON
    {
        "files": {
            "a.png": {
                "size": {$size},
                "xxh128": "{$hash}"
            },
            "b.png": {
                "size": {$size},
                "xxh128": "{$hash}"
            }
        }
    }
    JSON;

    expect(file_get_contents(workspace().'/'.BaselineFile::FILENAME))->toBe($expected.PHP_EOL);
});

test('an empty baseline saves as an empty files object', function () {
    mkdir(workspace(), 0755, true);

    BaselineFile::load(workspace())->save(workspace());

    expect(file_get_contents(workspace().'/'.BaselineFile::FILENAME))->toBe('{'.PHP_EOL.'    "files": {}'.PHP_EOL.'}'.PHP_EOL);
});

test('a saved baseline loads back with the same matching', function () {
    $path = createImage('photos/a.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photos/a.png', $path);
    $baseline->save(workspace());

    expect(BaselineFile::load(workspace())->skips('photos/a.png', $path))->toBeTrue();
});

test('load fails loudly on a malformed baseline', function (string $content) {
    mkdir(workspace(), 0755, true);
    file_put_contents(workspace().'/'.BaselineFile::FILENAME, $content);

    BaselineFile::load(workspace());
})->throws(ApiException::class, 'Malformed')->with([
    'invalid json' => '{nope',
    'not an object' => '[1, 2]',
    'missing files key' => '{"paths": {}}',
    'entry missing xxh128' => '{"files": {"a.png": {"size": 70}}}',
    'entry with non-integer size' => '{"files": {"a.png": {"size": "70", "xxh128": "abc"}}}',
]);

test('findRoot walks up to the nearest baseline', function () {
    createImage('nested/deep/photo.png');
    writeBaseline([]);

    expect(BaselineFile::findRoot(workspace().'/nested/deep'))->toBe(workspace())
        ->and(BaselineFile::findRoot(workspace()))->toBe(workspace());
});

test('findRoot returns null when no baseline exists up the tree', function () {
    createImage('nested/photo.png');

    expect(BaselineFile::findRoot(workspace().'/nested'))->toBeNull();
});
