<?php

use LaravelZero\Framework\Components\Updater\Strategy\GithubStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Self-updater Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used by the "self-update" command to locate new versions
    | of the application. The GitHub strategy discovers versions through
    | Packagist and downloads the committed builds/glimpse PHAR from the
    | matching tag, so tagging a release is all a new version needs.
    |
    */

    'strategy' => GithubStrategy::class,

];
