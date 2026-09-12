<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use MathiasGrimm\GlimpseCli\Support\Sleeper;
use PHPUnit\Framework\Assert;
use Tests\Fixtures\Images;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(Config::class, new Config(publicTokenOverride: 'public-test'));
$app->instance(Sleeper::class, new class extends Sleeper
{
    public function sleep(int $seconds): void {}
});

Http::fakeSequence()
    ->push(['message' => 'wait'], 429, ['Retry-After' => '1'])
    ->push(['data' => [
        'output' => ['type' => 'BASE64', 'data' => base64_encode(Images::jpg())],
        'format' => 'jpg', 'mime_type' => 'image/jpeg',
        'size' => strlen(Images::jpg()), 'width' => 1, 'height' => 1,
    ]]);

$status = Artisan::call('optimize', ['input' => '-', '--output' => '-']);
Http::assertSentCount(2);
Http::assertNotSent(fn (Request $request): bool => $request['input']['data'] !== base64_encode(Images::png()));
Assert::assertSame(0, $status);

exit($status);
