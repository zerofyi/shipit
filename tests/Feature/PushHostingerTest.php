<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Zerofyi\ShipIt\Support\Journal;
use Zerofyi\ShipIt\Tests\Fakes\FakeServer;
use Zerofyi\ShipIt\Tests\TestCase;

class PushHostingerTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidEnv();
        $this->server = new FakeServer();
        $this->server->register();
    }

    public function test_successful_deploy_backs_up_first_then_deploys_and_journals(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy v2'])
            ->assertExitCode(0);

        // The backup snapshot is created BEFORE the code sync touches the server.
        $backupAt = $this->server->remoteIndexOf('BACKUP_OK');
        $syncAt = $this->server->remoteIndexOf('git remote set-url origin');
        $this->assertGreaterThanOrEqual(0, $backupAt);
        $this->assertGreaterThan($backupAt, $syncAt);

        // Maintenance mode wraps the migration window.
        $downAt = $this->server->remoteIndexOf('php artisan down');
        $migrateAt = $this->server->remoteIndexOf('php artisan migrate --force');
        $upAt = $this->server->remoteIndexOf('php artisan up');
        $this->assertGreaterThanOrEqual(0, $downAt);
        $this->assertGreaterThan($downAt, $migrateAt);
        $this->assertGreaterThan($migrateAt, $upAt);

        // Queue restart is part of the pipeline now.
        $this->assertTrue($this->server->ranRemoteContaining('php artisan queue:restart'));

        // The journal closes the deploy as a success with its backup attached.
        $entries = $this->server->journalEntries();
        $deploy = end($entries);
        $this->assertSame('deploy', $deploy['type']);
        $this->assertSame('success', $deploy['status']);
        $this->assertNotEmpty($deploy['backup_id']);
        $this->assertFalse($deploy['backup_skipped']);
        $this->assertSame($this->server->localHead, $deploy['to_commit']);
    }

    public function test_backup_failure_aborts_before_any_server_change(): void
    {
        $this->server->failBackup = true;

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy v2'])
            ->assertExitCode(1);

        // Nothing on the server was synced, migrated or optimized.
        $this->assertFalse($this->server->ranRemoteContaining('git remote set-url origin'));
        $this->assertFalse($this->server->ranRemoteContaining('php artisan migrate'));
        $this->assertFalse($this->server->ranRemoteContaining('composer install'));

        $entries = $this->server->journalEntries();
        $deploy = end($entries);
        $this->assertSame('failed', $deploy['status']);
        $this->assertSame('backup', $deploy['failed_step']);
    }

    public function test_skip_backup_flag_is_recorded_honestly_in_journal(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy v2', '--skip-backup' => true])
            ->assertExitCode(0);

        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));

        $entries = $this->server->journalEntries();
        $deploy = end($entries);
        $this->assertSame('success', $deploy['status']);
        $this->assertTrue($deploy['backup_skipped']);
        $this->assertNull($deploy['backup_id']);
    }

    public function test_held_lock_blocks_the_deploy(): void
    {
        $this->server->lockTakenByOther = true;

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy v2'])
            ->assertExitCode(1);

        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));
        $this->assertFalse($this->server->ranRemoteContaining('git remote set-url origin'));
    }

    public function test_migration_failure_trips_circuit_breaker_and_still_brings_site_up(): void
    {
        $this->server->failOn = ['php artisan migrate --force' => true];

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy v2'])
            ->assertExitCode(1);

        // Maintenance mode was enabled, and — critically — disabled again
        // even though the deployment failed halfway.
        $this->assertTrue($this->server->ranRemoteContaining('php artisan down'));
        $this->assertTrue($this->server->ranRemoteContaining('php artisan up'));
        // Steps after the failed migration never ran.
        $this->assertFalse($this->server->ranRemoteContaining('php artisan optimize'));

        $entries = $this->server->journalEntries();
        $deploy = end($entries);
        $this->assertSame('failed', $deploy['status']);
        $this->assertSame('Run Migrations', $deploy['failed_step']);
        $this->assertNotEmpty($deploy['backup_id']);
    }

    public function test_failed_health_check_offers_and_performs_rollback(): void
    {
        // A previous successful deploy exists with its snapshot.
        $journal = Journal::append(Journal::empty(), [
            'type' => 'deploy', 'status' => 'success', 'at' => '2026-10-01 12:00:00',
            'branch' => 'main', 'to_commit' => 'oldcommit123456', 'backup_id' => '20261001-120000',
        ]);
        $this->server->journal = Journal::encode($journal);
        $this->server->snapshots['20261001-120000'] = [
            'id' => '20261001-120000',
            'created_at' => '2026-10-01 12:00:00',
            'commit' => 'oldcommit123456',
            'database' => ['method' => 'mysqldump', 'name' => 'prod_db', 'tables' => 12],
            'files' => ['db_bytes' => 1000, 'storage_bytes' => 2000, 'build_bytes' => 0, 'has_env' => true, 'has_storage' => true, 'has_build' => false],
        ];

        Http::fake(['*' => Http::response('server error', 500)]);

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'broken deploy'])
            ->expectsQuestion(' Roll back to the previous deploy right now? (y/N)', 'y')
            ->assertExitCode(0);

        // The rollback restored the previous commit.
        $this->assertTrue($this->server->ranRemoteContaining('git reset --hard'));
        $this->assertTrue($this->server->ranRemoteContaining('oldcommit123456'));

        // And the journal shows the whole story: deploy success, rollback success.
        $types = array_map(fn ($e) => $e['type'] . ':' . $e['status'], $this->server->journalEntries());
        $this->assertContains('rollback:success', $types);
    }

    public function test_token_scrub_after_rescue_sync(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Unit-level guarantee exercised via the URL helpers is covered in
        // UrlsTest; here we assert the normal (public repo) path never
        // writes a token URL to the server at all.
        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy'])
            ->assertExitCode(0);

        foreach ($this->server->remoteRan as $cmd) {
            $this->assertStringNotContainsString('x-access-token', $cmd);
        }
    }

    public function test_selective_deploy_is_journaled_as_partial(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->artisan('push:hostinger', [
            '--skip-assets' => true,
            '--message' => 'hotfix deploy',
            '--files' => 'app/Http/Controllers/HomeController.php',
        ])
            ->expectsQuestion(' Proceed with deploying only the selected files? (y/N)', 'y')
            ->assertExitCode(0);

        $entries = $this->server->journalEntries();
        $deploy = end($entries);
        $this->assertSame('success', $deploy['status']);
        $this->assertTrue($deploy['partial']);
        $this->assertSame(['app/Http/Controllers/HomeController.php'], $deploy['files']);
    }

    public function test_branch_mismatch_aborts_before_any_work(): void
    {
        $this->artisan('push:hostinger', [
            '--skip-assets' => true,
            '--message' => 'deploy',
            '--branch' => 'develop',
        ])->assertExitCode(1);

        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));
        $this->assertFalse($this->server->ranContaining('git push'));
    }

    public function test_dry_run_touches_nothing_on_the_server(): void
    {
        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));
        $this->assertFalse($this->server->ranRemoteContaining('git remote set-url origin'));
        $this->assertSame([], $this->server->journalWrites);
    }
}
