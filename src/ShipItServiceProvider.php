<?php

namespace Zerofyi\ShipIt;

use Illuminate\Support\ServiceProvider;
use Zerofyi\ShipIt\Commands\PushGithub;
use Zerofyi\ShipIt\Commands\PushHostinger;

class ShipItServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PushGithub::class,
                PushHostinger::class,
            ]);
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Left intentionally clean to optimize bootstrap runtimes
    }
}