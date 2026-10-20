<?php

namespace Zerofyi\ShipIt\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zerofyi\ShipIt\ShipItServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ShipItServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('shipit.backup.enabled', true);
        $app['config']->set('shipit.backup.retention', 3);
        $app['config']->set('shipit.backup.download', false);
        $app['config']->set('shipit.health_check.enabled', true);
        $app['config']->set('shipit.health_check.urls', []);
        $app['config']->set('shipit.hooks.before', []);
        $app['config']->set('shipit.hooks.after', []);
        $app['config']->set('shipit.sites', []);
    }

    /**
     * Set .env-style variables the way Laravel's env() reads them.
     */
    protected function setEnvVars(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /** A complete, valid single-site configuration used by most tests. */
    protected function useValidEnv(): void
    {
        $this->setEnvVars([
            'GITHUB_REPO_URL' => 'https://github.com/zerofyi/mobilebazar.git',
            'GITHUB_API_TOKEN' => 'ghp_testtoken_testtoken_testtoken_1234',
            'HOSTINGER_SSH_HOST' => 'srv123.hostinger.com',
            'HOSTINGER_SSH_USERNAME' => 'u123456789',
            'HOSTINGER_SSH_PORT' => '65002',
            'HOSTINGER_SITE_DIR' => 'shop.com',
        ]);
    }
}
