<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Images;

function skipGit(array $arguments): string
{
    $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$arguments], workspace());
    $process->mustRun();

    return trim($process->getOutput());
}

beforeEach(function () {
    chdirWorkspace();
    Http::fake();
    skipGit(['init', '-q']);
});

test('skip restores an uncommitted optimization and only accepts the selected file', function (string $name) {
    file_put_contents(workspace().'/'.$name, Images::png());
    file_put_contents(workspace().'/other.png', Images::png());
    skipGit(['add', '--', $name, 'other.png']);
    skipGit(['commit', '-qm', 'Original images']);
    file_put_contents(workspace().'/'.$name, Images::jpg());
    file_put_contents(workspace().'/other.png', Images::jpg());

    expect(Artisan::call('skip', ['input' => $name]))->toBe(0)
        ->and(file_get_contents(workspace().'/'.$name))->toBe(Images::png())
        ->and(file_get_contents(workspace().'/other.png'))->toBe(Images::jpg());

    $baseline = json_decode(file_get_contents(baselinePath()), true);
    expect(array_keys($baseline['files']))->toBe([$name])
        ->and($baseline['files'][$name]['via'])->toBe('skip')
        ->and(BaselineFile::load(workspace())->skips($name, workspace().'/'.$name))->toBeTrue();
    file_put_contents(workspace().'/'.$name, Images::jpg());
    clearstatcache();
    expect(BaselineFile::load(workspace())->skips($name, workspace().'/'.$name))->toBeFalse();
    Http::assertNothingSent();
})->with(['photo.png', 'a space.png', '-leading.png', "a\nnewline.png", 'literal[1].png']);

test('skip restores from an explicit earlier commit without changing the index', function () {
    $path = workspace().'/photo.png';
    file_put_contents($path, Images::png());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Original']);
    $original = skipGit(['rev-parse', 'HEAD']);
    file_put_contents($path, Images::jpg());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Optimized']);
    $index = skipGit(['ls-files', '--stage']);

    expect(Artisan::call('skip', ['input' => $path, '--from' => $original]))->toBe(0)
        ->and(file_get_contents($path))->toBe(Images::png())
        ->and(skipGit(['ls-files', '--stage']))->toBe($index);
});

test('skip refuses unavailable originals without changing the file or baseline', function (string $from) {
    file_put_contents(workspace().'/tracked.png', Images::png());
    skipGit(['add', 'tracked.png']);
    skipGit(['commit', '-qm', 'Original']);
    file_put_contents(workspace().'/new.png', Images::png());
    $baseline = BaselineFile::load(workspace(), forUpdate: true);
    $baseline->record('tracked.png', workspace().'/tracked.png', 'analyze');
    $baseline->save(workspace());
    $before = file_get_contents(baselinePath());

    expect(Artisan::call('skip', ['input' => 'new.png', '--from' => $from]))->toBe(1)
        ->and(file_get_contents(workspace().'/new.png'))->toBe(Images::png())
        ->and(file_get_contents(baselinePath()))->toBe($before);
    Http::assertNothingSent();
})->with(['HEAD', 'no-such-ref', '--help']);

test('skip validates the baseline before restoring any bytes', function () {
    file_put_contents(workspace().'/photo.png', Images::png());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Original']);
    file_put_contents(workspace().'/photo.png', Images::jpg());
    file_put_contents(baselinePath(), 'broken');

    expect(Artisan::call('skip', ['input' => 'photo.png']))->toBe(1)
        ->and(file_get_contents(workspace().'/photo.png'))->toBe(Images::jpg())
        ->and(file_get_contents(baselinePath()))->toBe('broken');
});

test('skip rolls the image back when a baseline cannot be created', function () {
    file_put_contents(workspace().'/photo.png', Images::png());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Original']);
    file_put_contents(workspace().'/photo.png', Images::jpg());
    mkdir(baselinePath());

    expect(Artisan::call('skip', ['input' => 'photo.png']))->toBe(1)
        ->and(file_get_contents(workspace().'/photo.png'))->toBe(Images::jpg());
});

test('skip refuses a symlink without modifying its target', function () {
    file_put_contents(workspace().'/target.png', Images::png());
    symlink('target.png', workspace().'/link.png');
    skipGit(['add', '.']);
    skipGit(['commit', '-qm', 'Symlink']);

    expect(Artisan::call('skip', ['input' => 'link.png']))->toBe(1)
        ->and(is_link(workspace().'/link.png'))->toBeTrue()
        ->and(file_get_contents(workspace().'/target.png'))->toBe(Images::png())
        ->and(file_exists(baselinePath()))->toBeFalse();
});

test('skip refuses a linked baseline before restoring the image', function () {
    file_put_contents(workspace().'/photo.png', Images::png());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Original']);
    file_put_contents(workspace().'/photo.png', Images::jpg());
    file_put_contents(workspace().'/shared.json', '{"files":{}}');
    symlink('shared.json', baselinePath());

    expect(Artisan::call('skip', ['input' => 'photo.png']))->toBe(1)
        ->and(file_get_contents(workspace().'/photo.png'))->toBe(Images::jpg())
        ->and(is_link(baselinePath()))->toBeTrue()
        ->and(file_get_contents(workspace().'/shared.json'))->toBe('{"files":{}}');
});

test('skip restores a deleted tracked image', function () {
    file_put_contents(workspace().'/photo.png', Images::png());
    skipGit(['add', 'photo.png']);
    skipGit(['commit', '-qm', 'Original']);
    unlink(workspace().'/photo.png');

    expect(Artisan::call('skip', ['input' => 'photo.png']))->toBe(0)
        ->and(file_get_contents(workspace().'/photo.png'))->toBe(Images::png())
        ->and(BaselineFile::load(workspace())->skips('photo.png', workspace().'/photo.png'))->toBeTrue();
});
