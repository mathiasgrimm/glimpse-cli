<?php

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        $this->configHome = sys_get_temp_dir().'/glimpse-cli-test-'.bin2hex(random_bytes(6));

        putenv('XDG_CONFIG_HOME='.$this->configHome);
        putenv('GLIMPSE_TOKEN');
        putenv('GLIMPSE_API_URL');
    })
    ->afterEach(function () {
        if ($this->configHome !== '' && is_dir($this->configHome)) {
            File::deleteDirectory($this->configHome);
        }

        putenv('XDG_CONFIG_HOME');
    })
    ->in('Feature', 'Unit');
