<?php

use Symfony\Component\Process\Process;
use Tests\Fixtures\Images;

test('stdin survives a retry and binary stdout contains only the successful image', function () {
    $root = base_path();
    $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/run-stdin-optimize.php'], $root, [
        'GLIMPSE_TOKEN' => false,
        'GLIMPSE_API_URL' => false,
    ]);
    $process->setInput(Images::png());
    $process->mustRun();

    expect($process->getOutput())->toBe(Images::jpg())
        ->and($process->getErrorOutput())->toContain('Rate limited; retrying in 1s.', 'Wrote stdout');
});
