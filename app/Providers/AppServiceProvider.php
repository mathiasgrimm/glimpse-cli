<?php

namespace App\Providers;

use App\Glimpse\Client;
use App\Glimpse\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Config::class);
        $this->app->singleton(Client::class);
    }
}
