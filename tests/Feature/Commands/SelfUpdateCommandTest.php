<?php

use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Components\Updater\Strategy\GithubStrategy;

test('the self-update command is only available inside a production build', function () {
    expect(Artisan::all())->not->toHaveKey('self-update');
});

test('the updater downloads the committed PHAR from the tagged release', function () {
    expect(config('updater.strategy'))->toBe(GithubStrategy::class);
});
