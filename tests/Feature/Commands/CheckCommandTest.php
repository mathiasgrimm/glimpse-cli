<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

/**
 * Fake the analyze endpoint. The default canned response saves 81.2%
 * at best; tests exercising the passing path fake a response whose
 * best saving stays under the threshold instead. One fake per test:
 * stacked Http::fake() stubs resolve in registration order, so a
 * beforeEach default could not be overridden.
 */
function fakeAnalyze(?float $bestPercent = null): void
{
    Http::fake(['*/v1/analyze' => Http::response($bestPercent === null ? fakeAnalyzeResponse() : ['data' => [
        ['format' => 'webp', 'size' => 68, 'saved' => 2, 'saved_percent' => $bestPercent, 'quality' => 85],
        ['format' => 'png', 'size' => 71, 'saved' => -1, 'saved_percent' => -1.4, 'quality' => null],
    ]])]);
}

test('rides out a rate limit waiting the Retry-After delay and still succeeds', function () {
    $sleeper = fakeSleeper();

    Http::fake(['*/v1/analyze' => Http::sequence()
        ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '17'])
        ->push(['data' => [
            ['format' => 'webp', 'size' => 68, 'saved' => 2, 'saved_percent' => 2.8, 'quality' => 85],
        ]])]);

    $path = createImage();

    expect(Artisan::call('check', ['input' => $path]))->toBe(0)
        ->and($sleeper->delays)->toBe([17]);

    Http::assertSentCount(2);
});

test('a rate limit without Retry-After waits the default delay', function () {
    $sleeper = fakeSleeper();

    Http::fake(['*/v1/analyze' => Http::sequence()
        ->push(['message' => 'Too Many Requests'], 429)
        ->push(['data' => [
            ['format' => 'webp', 'size' => 68, 'saved' => 2, 'saved_percent' => 2.8, 'quality' => 85],
        ]])]);

    $path = createImage();

    expect(Artisan::call('check', ['input' => $path]))->toBe(0)
        ->and($sleeper->delays)->toBe([5]);
});

test('a Retry-After beyond the cap aborts the batch without retrying or sleeping', function () {
    $sleeper = fakeSleeper();

    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '300'])]);

    createImage('a.png');
    createImage('b.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Retry after 300 seconds.')
        ->and($sleeper->delays)->toBe([]);

    // The first file aborts the whole batch: the second file is never sent.
    Http::assertSentCount(1);
});

test('gives up after repeated rate limits and suggests a personal token on the public one', function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
    $sleeper = fakeSleeper();

    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '0'])]);

    $path = createImage();

    $exitCode = Artisan::call('check', ['input' => $path]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Too Many Requests')
        ->and($output)->toContain('Get your own free token at https://glimpseimg.com')
        ->and($sleeper->delays)->toBe([0, 0, 0]);

    // One initial attempt plus three retries, then the batch aborts.
    Http::assertSentCount(4);
});

test('an ability rejection aborts the batch with the public token hint', function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));

    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Invalid ability provided.'], 403)]);

    createImage('a.png');
    createImage('b.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Invalid ability provided.')
        ->and($output)->toContain('Get your own free token at https://glimpseimg.com');

    Http::assertSentCount(1);
});

test('a rejected public token gets the hint on 401 too', function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));

    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $path = createImage();

    $exitCode = Artisan::call('check', ['input' => $path]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Run: glimpse auth')
        ->and($output)->toContain('Get your own free token at https://glimpseimg.com');
});

test('lists images over the threshold and exits 1', function () {
    fakeAnalyze();
    createImage('photo.png');
    createImage('nested/big.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('photo.png')
        ->and($output)->toContain('nested/big.png')
        ->and($output)->toContain('70 B')
        ->and($output)->toContain('~459 KB')
        ->and($output)->toContain('AVIF')
        ->and($output)->toContain('81.2%')
        ->and($output)->toContain('2 of 2 images need optimization (threshold: 10%).');
});

test('passes when every image is within the threshold', function () {
    fakeAnalyze(bestPercent: 3.2);

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('The 1 image is within the 10% threshold.')
        ->and($output)->not->toContain('photo.png');
});

test('flags a saving exactly at the threshold', function () {
    fakeAnalyze(bestPercent: 10.0);

    createImage('photo.png');

    expect(Artisan::call('check', ['input' => workspace()]))->toBe(1);
});

test('respects a custom --threshold', function () {
    fakeAnalyze();
    createImage('photo.png');

    expect(Artisan::call('check', ['input' => workspace(), '--threshold' => 90]))->toBe(0)
        ->and(Artisan::call('check', ['input' => workspace(), '--threshold' => 50]))->toBe(1);
});

test('rejects invalid thresholds before calling the api', function (string $threshold) {
    fakeAnalyze();
    createImage('photo.png');

    $this->artisan('check', ['input' => workspace(), '--threshold' => $threshold])
        ->expectsOutputToContain('The --threshold option must be a number between 0 and 100.')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['abc', '-5', '150']);

test('checks a single file by its basename', function () {
    fakeAnalyze();
    $path = createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => $path]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('photo.png')
        ->and($output)->toContain('1 of 1 image needs optimization');
});

test('fails cleanly when the path does not exist', function () {
    fakeAnalyze();
    $this->artisan('check', ['input' => '/nope/missing.jpg'])
        ->expectsOutputToContain('File not found: /nope/missing.jpg')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('emits a json report when failing', function () {
    fakeAnalyze();
    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($decoded['threshold'])->toEqual(10)
        ->and($decoded['total'])->toBe(1)
        ->and($decoded['needs_optimization'])->toBe(1)
        ->and($decoded['files'][0]['file'])->toBe('photo.png')
        ->and($decoded['files'][0]['format'])->toBe('avif')
        ->and($decoded['files'][0]['saved_percent'])->toBe(81.2)
        ->and($decoded['failed'])->toBe([]);
});

test('emits a json report when passing', function () {
    fakeAnalyze(bestPercent: 3.2);

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['total'])->toBe(1)
        ->and($decoded['needs_optimization'])->toBe(0)
        ->and($decoded['files'])->toBe([]);
});

test('fails when a file cannot be checked', function () {
    fakeAnalyze(bestPercent: 3.2);

    createImage('good.png');
    createImage('bad.png', 'not an image');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('1 file could not be checked:')
        ->and($output)->toContain('bad.png')
        ->and($output)->toContain('Unrecognized image format.');
});

test('passes when the directory contains no images', function () {
    fakeAnalyze();
    mkdir(workspace(), 0755, true);

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No images found in');

    Http::assertNothingSent();
});

test('passes when .glimpseignore excludes every offender', function () {
    chdirWorkspace();
    fakeAnalyze();
    createImage('photo.png');
    file_put_contents(workspace().'/.glimpseignore', "*.png\n");

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No images found in');

    Http::assertNothingSent();
});

test('reports the baseline-skipped count alongside remaining offenders', function () {
    chdirWorkspace();
    fakeAnalyze();
    createImage('photo.png');
    writeBaseline(['covered.png' => baselineEntry(createImage('covered.png'))]);

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('1 of 1 image needs optimization')
        ->and($output)->toContain('1 file skipped by baseline.');

    Http::assertSentCount(1);
});

test('the cwd baseline governs a subdirectory scan', function () {
    chdirWorkspace();
    fakeAnalyze();
    $covered = createImage('sub/photo.png');
    writeBaseline(['sub/photo.png' => baselineEntry($covered)]);

    $exitCode = Artisan::call('check', ['input' => workspace().'/sub']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('The 1 image is covered by the baseline.');

    Http::assertNothingSent();
});

test('a baseline is not picked up from outside the current working directory', function () {
    fakeAnalyze();
    writeBaseline(['photo.png' => baselineEntry(createImage('photo.png'))]);
    chdirWorkspace(workspace().'/elsewhere');

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('1 of 1 image needs optimization');

    Http::assertSentCount(1);
});

test('the json report for a fully covered directory is machine readable', function () {
    chdirWorkspace();
    fakeAnalyze();
    writeBaseline(['photo.png' => baselineEntry(createImage('photo.png'))]);

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['total'])->toBe(0)
        ->and($decoded['needs_optimization'])->toBe(0)
        ->and($decoded['baseline_skipped'])->toBe(1);

    Http::assertNothingSent();
});

test('the json report includes the baseline-skipped count', function () {
    chdirWorkspace();
    fakeAnalyze();
    createImage('photo.png');
    writeBaseline(['covered.png' => baselineEntry(createImage('covered.png'))]);

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($decoded['total'])->toBe(1)
        ->and($decoded['baseline_skipped'])->toBe(1);
});

test('fails with an auth error when no token is configured', function () {
    fakeAnalyze();
    putenv('GLIMPSE_TOKEN');

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Not authenticated. Run: glimpse auth');

    Http::assertNothingSent();
});

describe('--fix', function () {
    beforeEach(function () {
        chdirWorkspace();
        Http::preventStrayRequests();
    });

    test('checks every image before optimizing only flagged files with the optimize default', function () {
        $first = createImage('first.jpeg', Images::jpg().'padding');
        $second = createImage('second.jpeg', Images::jpg().'padding');
        $requests = [];

        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request->url();

            if (str_ends_with($request->url(), '/analyze')) {
                $response = fakeAnalyzeResponse();
                if (count($requests) === 2) {
                    foreach ($response['data'] as &$estimate) {
                        $estimate['saved_percent'] = 0;
                    }
                }

                return Http::response($response);
            }

            expect(count($requests))->toBe(3)
                ->and($request->data())->not->toHaveKey('quality');

            return Http::response(fakeTransformResponse());
        });

        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--json' => true]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['fixed'])->toBe(['first.jpeg'])
            ->and($report['failed'])->toBe([])
            ->and(file_get_contents($first))->toBe(Images::jpg())
            ->and(file_get_contents($second))->toBe(Images::jpg().'padding')
            ->and(file_exists(baselinePath()))->toBeFalse();
    });

    test('preserves filenames and updates the baseline so the next scan skips fixed images', function (string $filename) {
        fakeAnalyze();
        fakeTransform('optimize');
        $path = createImage($filename, Images::jpg().'padding');
        writeBaseline();

        expect(Artisan::call('check', ['input' => '.', '--fix' => true]))->toBe(0)
            ->and(file_get_contents($path))->toBe(Images::jpg())
            ->and(baselineFiles())->toBe([$filename => baselineEntry($path, 'optimize')]);

        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--json' => true]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['baseline_skipped'])->toBe(1)->and($report['fixed'])->toBe([]);
        Http::assertSentCount(2);
    })->with(['photo.jpeg', 'PHOTO.JPG', '-leading.jpeg', 'nested/space and "quote".jpeg', "newline\nimage.jpeg"]);

    test('passes explicit quality to optimize without changing checking estimates', function () {
        fakeAnalyze();
        fakeTransform('optimize');
        $path = createImage();

        $this->artisan('check', ['input' => $path, '--fix' => true, '--quality' => '73'])
            ->expectsOutputToContain('Optimized 1 image.')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/optimize') && $request['quality'] === 73);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/analyze') && ! array_key_exists('quality', $request->data()));
    });

    test('rejects invalid quality and quality without fix before requests', function (array $options, string $message) {
        Http::fake();
        $this->artisan('check', ['input' => createImage()] + $options)
            ->expectsOutputToContain($message)
            ->assertExitCode(1);
        Http::assertNothingSent();
    })->with([
        'non-numeric quality' => [['--fix' => true, '--quality' => 'high'], 'The --quality option must be a number.'],
        'quality without fix' => [['--quality' => '85'], '--quality requires --fix.'],
    ]);

    test('leaves every image untouched if checking any file fails', function () {
        fakeAnalyze();
        $path = createImage('good.png');
        createImage('broken.png', 'broken');
        writeBaseline();

        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--json' => true]))->toBe(1);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['fixed'])->toBe([])
            ->and($report['failed'][0]['file'])->toBe('broken.png')
            ->and(file_get_contents($path))->toBe(Images::png())
            ->and(baselineFiles())->toBe([]);
        Http::assertSentCount(1);
    });

    test('respects ignore rules and the threshold when fixing', function () {
        fakeAnalyze();
        createImage('ignored.png');
        createImage('within-threshold.png');
        file_put_contents(workspace().'/.glimpseignore', 'ignored.png');

        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--threshold' => '90', '--json' => true]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['total'])->toBe(1)->and($report['fixed'])->toBe([]);
        Http::assertSentCount(1);
    });

    test('succeeds on an empty directory without making requests', function () {
        Http::fake();
        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--json' => true]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['total'])->toBe(0)->and($report['fixed'])->toBe([]);
        Http::assertNothingSent();
    });

    test('retries optimization rate limits using the existing retry behavior', function () {
        fakeAnalyze();
        $sleeper = fakeSleeper();
        Http::fake(['*/v1/optimize' => Http::sequence()
            ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '2'])
            ->push(fakeTransformResponse())]);

        $this->artisan('check', ['input' => createImage(), '--fix' => true])->assertExitCode(0);
        expect($sleeper->delays)->toBe([2]);
        Http::assertSentCount(3);
    });

    test('stops on an optimization failure and reports earlier successful writes', function () {
        fakeAnalyze();
        Http::fake(['*/v1/optimize' => Http::sequence()
            ->push(fakeTransformResponse())
            ->push(['message' => 'Optimization failed'], 500)]);
        foreach (['a.jpeg', 'b.jpeg', 'c.jpeg'] as $name) {
            createImage($name, Images::jpg().'padding');
        }
        writeBaseline();

        expect(Artisan::call('check', ['input' => '.', '--fix' => true, '--json' => true]))->toBe(1);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['fixed'])->toBe(['a.jpeg'])
            ->and($report['failed'][0]['file'])->toBe('b.jpeg')
            ->and($report['failed'][0]['error'])->toContain('Optimization failed')
            ->and(file_get_contents(workspace().'/a.jpeg'))->toBe(Images::jpg())
            ->and(file_get_contents(workspace().'/b.jpeg'))->toBe(Images::jpg().'padding')
            ->and(file_get_contents(workspace().'/c.jpeg'))->toBe(Images::jpg().'padding')
            ->and(array_keys(baselineFiles()))->toBe(['a.jpeg']);
        Http::assertSentCount(5);
    });
});
