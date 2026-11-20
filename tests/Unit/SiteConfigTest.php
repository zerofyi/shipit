<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\HealthCheck;
use Zerofyi\ShipIt\Support\SiteConfig;

class SiteConfigTest extends TestCase
{
    private function validServerCfg(): array
    {
        return [
            'site' => 'default',
            'repo_url' => 'https://github.com/zerofyi/mobilebazar.git',
            'github_token' => 'ghp_1234567890123456789012345678901234',
            'ssh_host' => 'srv123.hostinger.com',
            'ssh_user' => 'u123456789',
            'ssh_port' => 65002,
            'site_dir' => 'shop.com',
        ];
    }

    public function test_valid_config_passes(): void
    {
        $this->assertSame([], SiteConfig::validateGithub($this->validServerCfg()));
        $this->assertSame([], SiteConfig::validateServer($this->validServerCfg()));
        $this->assertSame([], SiteConfig::validateServer($this->validServerCfg(), needGithub: false));
    }

    public function test_invalid_repo_url_is_caught(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['repo_url'] = 'not-a-url';
        $this->assertNotSame([], SiteConfig::validateGithub($cfg));

        $cfg['repo_url'] = '';
        $this->assertNotSame([], SiteConfig::validateGithub($cfg));

        $cfg['repo_url'] = 'git@github.com:zerofyi/mobilebazar.git';
        $this->assertSame([], SiteConfig::validateGithub($cfg));
    }

    public function test_short_token_is_flagged(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['github_token'] = 'short';
        $this->assertNotSame([], SiteConfig::validateGithub($cfg));
    }

    /**
     * Regression (v2.1.0): the token was wrongly made mandatory, which
     * broke push:github on fresh setups — push:github never uses the
     * token, and push:hostinger only needs it for automatic deploy-key
     * injection on private repos.
     */
    public function test_missing_token_is_allowed(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['github_token'] = null;
        $this->assertSame([], SiteConfig::validateGithub($cfg));
        $this->assertSame([], SiteConfig::validateServer($cfg));

        $cfg['github_token'] = '';
        $this->assertSame([], SiteConfig::validateGithub($cfg));
    }

    public function test_path_traversal_in_site_dir_is_refused(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['site_dir'] = '../../etc';
        $this->assertNotSame([], SiteConfig::validateServer($cfg));

        $cfg['site_dir'] = 'shop.com/../../x';
        $this->assertNotSame([], SiteConfig::validateServer($cfg));

        $cfg['site_dir'] = 'sub.shop-example.com';
        $this->assertSame([], SiteConfig::validateServer($cfg));
    }

    public function test_shell_metacharacters_in_host_and_user_are_refused(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['ssh_host'] = 'host; rm -rf /';
        $this->assertNotSame([], SiteConfig::validateServer($cfg));

        $cfg = $this->validServerCfg();
        $cfg['ssh_user'] = 'user$(whoami)';
        $this->assertNotSame([], SiteConfig::validateServer($cfg));
    }

    public function test_port_range(): void
    {
        $cfg = $this->validServerCfg();
        $cfg['ssh_port'] = 99999;
        $this->assertNotSame([], SiteConfig::validateServer($cfg));
        $cfg['ssh_port'] = 22;
        $this->assertSame([], SiteConfig::validateServer($cfg));
    }

    public function test_health_status_window(): void
    {
        $this->assertTrue(HealthCheck::isHealthyStatus(200));
        $this->assertTrue(HealthCheck::isHealthyStatus(301));
        $this->assertTrue(HealthCheck::isHealthyStatus(399));
        $this->assertFalse(HealthCheck::isHealthyStatus(404));
        $this->assertFalse(HealthCheck::isHealthyStatus(500));
        $this->assertFalse(HealthCheck::isHealthyStatus(0));
    }
}
