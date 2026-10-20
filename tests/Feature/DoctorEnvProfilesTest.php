<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Zerofyi\ShipIt\Support\SiteConfig;
use Zerofyi\ShipIt\Tests\Fakes\FakeServer;
use Zerofyi\ShipIt\Tests\TestCase;

class DoctorEnvProfilesTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidEnv();
        $this->server = new FakeServer();
        $this->server->register();
    }

    // ------------------------------------------------------------- doctor

    public function test_doctor_passes_on_a_healthy_server(): void
    {
        $this->artisan('shipit:doctor')->assertExitCode(0);
    }

    public function test_doctor_fails_when_server_git_is_missing(): void
    {
        $this->server->doctorFacts['has_git'] = '0';
        $this->artisan('shipit:doctor')->assertExitCode(1);
    }

    public function test_doctor_warns_but_passes_when_exec_is_disabled(): void
    {
        $this->server->doctorFacts['fn_exec'] = '0';
        $this->artisan('shipit:doctor')->assertExitCode(0);
    }

    public function test_doctor_fails_when_database_unreadable(): void
    {
        $this->server->dbInfo = '';
        $this->artisan('shipit:doctor')->assertExitCode(1);
    }

    // ----------------------------------------------------------- env sync

    public function test_env_sync_applies_diff_with_backup_and_journal(): void
    {
        $local = "APP_NAME=Prod\nAPP_KEY=base64:newkey\nNEW_FLAG=on\n";
        $path = base_path('.env.production');
        file_put_contents($path, $local);

        try {
            $this->server->serverEnv = "APP_NAME=Prod\nAPP_KEY=base64:existingkey\nOLD_FLAG=off\n";

            $this->artisan('shipit:env', ['--yes' => true])
                ->expectsOutputToContain('~ CHANGE  APP_KEY')
                ->assertExitCode(0);

            // New content landed on the server...
            $this->assertSame($local, $this->server->uploadedEnv);
            // ...the old one was backed up first...
            $this->assertCount(1, $this->server->envBackups);
            $this->assertStringContainsString('base64:existingkey', $this->server->envBackups[0]);
            // ...and the sync is in the journal.
            $entries = $this->server->journalEntries();
            $last = end($entries);
            $this->assertSame('env_sync', $last['type']);
            $this->assertSame('success', $last['status']);
        } finally {
            @unlink($path);
        }
    }

    public function test_env_sync_is_a_noop_when_already_in_sync(): void
    {
        $path = base_path('.env.production');
        file_put_contents($path, "APP_NAME=Prod\nAPP_KEY=base64:existingkey\nDB_DATABASE=prod_db\n");

        try {
            $this->artisan('shipit:env', ['--yes' => true])
                ->expectsOutputToContain('Already in sync')
                ->assertExitCode(0);

            $this->assertNull($this->server->uploadedEnv);
        } finally {
            @unlink($path);
        }
    }

    public function test_env_sync_creates_env_when_server_has_none(): void
    {
        $path = base_path('.env.production');
        file_put_contents($path, "APP_NAME=Prod\nDB_DATABASE=prod_db\n");

        try {
            $this->server->serverEnv = null;

            $this->artisan('shipit:env', ['--yes' => true])->assertExitCode(0);

            $this->assertSame("APP_NAME=Prod\nDB_DATABASE=prod_db\n", $this->server->uploadedEnv);
            // Nothing existed to back up.
            $this->assertSame([], $this->server->envBackups);
        } finally {
            @unlink($path);
        }
    }

    public function test_env_sync_requires_the_local_file(): void
    {
        $path = base_path('.env.production');
        @unlink($path);
        $this->artisan('shipit:env', ['--yes' => true])->assertExitCode(1);
    }

    // ------------------------------------------------------------- profiles

    public function test_profile_resolution_overrides_env_and_falls_back(): void
    {
        config(['shipit.sites' => [
            'blog' => [
                'site_dir' => 'blog.com',
                'repo_url' => 'https://github.com/zerofyi/blog.git',
            ],
        ]]);

        $cfg = SiteConfig::resolve('blog');
        $this->assertSame('blog.com', $cfg['site_dir']);
        $this->assertSame('https://github.com/zerofyi/blog.git', $cfg['repo_url']);
        // Untouched keys fall back to .env values:
        $this->assertSame('u123456789', $cfg['ssh_user']);
        $this->assertSame(65002, $cfg['ssh_port']);
        $this->assertSame('blog', $cfg['site']);

        $this->assertTrue(SiteConfig::profileExists('blog'));
        $this->assertFalse(SiteConfig::profileExists('nope'));
        $this->assertSame(['blog'], SiteConfig::profileNames());
    }

    public function test_unknown_profile_is_rejected_by_commands(): void
    {
        $this->artisan('shipit:backups', ['--site' => 'nope'])->assertExitCode(1);
    }

    public function test_profile_deploy_targets_the_profile_site(): void
    {
        config(['shipit.sites' => [
            'blog' => ['site_dir' => 'blog.com', 'repo_url' => 'https://github.com/zerofyi/blog.git'],
        ]]);

        // Backups for the "blog" profile must go under blog.com's own
        // per-site data root — never the default site's.
        $this->artisan('shipit:backup', ['--site' => 'blog'])->assertExitCode(0);

        $journalWrites = array_filter($this->server->remoteRan, fn ($c) => str_contains($c, 'shipit-backups/blog.com/journal.json'));
        $this->assertNotSame([], $journalWrites);
        $wrongSite = array_filter($this->server->remoteRan, fn ($c) => str_contains($c, 'shipit-backups/shop.com'));
        $this->assertSame([], $wrongSite);
    }
}
