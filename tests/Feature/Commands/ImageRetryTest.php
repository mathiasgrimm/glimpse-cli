<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;

beforeEach(function () {
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
});

dataset('retry image commands', [
    ['convert', ['--format' => 'jpg']], ['optimize', []], ['resize', ['--width' => 6]], ['thumbnail', []], ['info', []],
]);

test('retries image calls with the same bytes then writes only the successful result', function (string $command, array $options) {
    $sleeper = fakeSleeper();
    $response = $command === 'info' ? ['data' => fullInfoApiResponse()] : fakeTransformResponse();
    Http::fake(['*/v1/'.$command => Http::sequence()->push(['message' => 'wait'], 429, ['Retry-After' => '1'])->push($response)]);
    $path = createImage();
    chdirWorkspace();
    writeBaseline();

    expect(Artisan::call($command, ['input' => $path, ...$options, '--json' => true]))->toBe(0)
        ->and($sleeper->delays)->toBe([1]);
    Http::assertSentCount(2);
    $requests = Http::recorded();
    expect($requests[0][0]->body())->toBe($requests[1][0]->body())
        ->and(json_decode(Artisan::output(), true))->toBeArray();
})->with('retry image commands');

test('exhausted retries preserve input output and baseline', function (string $command, array $options) {
    $sleeper = fakeSleeper();
    Http::fake(['*/v1/'.$command => Http::response(['message' => 'wait'], 429)]);
    $path = createImage();
    $before = file_get_contents($path);
    chdirWorkspace();
    writeBaseline();
    $baseline = file_get_contents(baselinePath());
    $options = $command === 'info' ? $options : [...$options, '--output' => $path, '--force' => true];

    expect(Artisan::call($command, ['input' => $path, ...$options, '--json' => true]))->toBe(1)
        ->and($sleeper->delays)->toBe([5, 5, 5])
        ->and(Artisan::output())->toBe('')
        ->and(file_get_contents($path))->toBe($before)
        ->and(file_get_contents(baselinePath()))->toBe($baseline);
    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request): bool => $request['input']['data'] !== base64_encode($before));
})->with('retry image commands');

test('long rate limits and other errors do not retry', function (int $status, array $headers) {
    $sleeper = fakeSleeper();
    Http::fake(['*' => Http::response(['message' => 'failed'], $status, $headers)]);
    expect(Artisan::call('optimize', ['input' => createImage(), '--json' => true]))->toBe(1)
        ->and($sleeper->delays)->toBe([])
        ->and(Artisan::output())->toBe('');
    Http::assertSentCount(1);
})->with([[429, ['Retry-After' => '61']], [401, []], [403, []], [503, []]]);
