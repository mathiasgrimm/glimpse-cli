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
    | matching tag, so tagging a release is all a new version needs. The
    | build must be stamped with the tag name verbatim (see README), since
    | the updater compares build version and tag as plain strings.
    |
    */

    'strategy' => GithubStrategy::class,

];
