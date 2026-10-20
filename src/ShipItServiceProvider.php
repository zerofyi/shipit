<?php

namespace Zerofyi\ShipIt;

use Illuminate\Support\ServiceProvider;
use Zerofyi\ShipIt\Commands\BackupCommand;
use Zerofyi\ShipIt\Commands\BackupsCommand;
use Zerofyi\ShipIt\Commands\DoctorCommand;
use Zerofyi\ShipIt\Commands\EnvSyncCommand;
use Zerofyi\ShipIt\Commands\PushGithub;
use Zerofyi\ShipIt\Commands\PushHostinger;
use Zerofyi\ShipIt\Commands\RollbackCommand;

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
                DoctorCommand::class,
                BackupCommand::class,
                BackupsCommand::class,
                RollbackCommand::class,
                EnvSyncCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/shipit.php' => config_path('shipit.php'),
            ], 'shipit-config');
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/shipit.php', 'shipit');
    }
}
