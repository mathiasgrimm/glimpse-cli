<?php

use Symfony\Component\Process\Process;

test('stdin survives a retry and binary stdout contains only the successful image', function () {
    $bootstrap = var_export(base_path(), true);
    $script = <<<'SCRIPT'
require REPO.'/vendor/autoload.php';
$app = require REPO.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->instance(MathiasGrimm\GlimpseCli\Glimpse\Config::class, new MathiasGrimm\GlimpseCli\Glimpse\Config(publicTokenOverride: 'public-test'));
$app->instance(MathiasGrimm\GlimpseCli\Support\Sleeper::class, new class extends MathiasGrimm\GlimpseCli\Support\Sleeper {
    public function sleep(int $seconds): void {}
});
$calls = 0;
Illuminate\Support\Facades\Http::fake(function ($request) use (&$calls) {
    if ($request['input']['data'] !== base64_encode('stdin-image-bytes')) {
        throw new RuntimeException('stdin was not replayed');
    }
    $calls++;
    if ($calls === 1) {
        return Illuminate\Support\Facades\Http::response(['message' => 'wait'], 429, ['Retry-After' => '1']);
    }
    return Illuminate\Support\Facades\Http::response(['data' => [
        'output' => ['type' => 'BASE64', 'data' => base64_encode('successful-binary')],
        'format' => 'png', 'mime_type' => 'image/png', 'size' => 17, 'width' => 1, 'height' => 1,
    ]]);
});
exit(Illuminate\Support\Facades\Artisan::call('optimize', ['input' => '-', '--output' => '-']));
SCRIPT;
    $process = new Process([PHP_BINARY, '-r', str_replace('REPO', $bootstrap, $script)], base_path());
    $process->setInput('stdin-image-bytes');
    $process->mustRun();
    expect($process->getOutput())->toBe('successful-binary')
        ->and($process->getErrorOutput())->toContain('Rate limited; retrying in 1s.', 'Wrote stdout');
});
