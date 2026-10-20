<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Zerofyi\ShipIt\Support\Journal;
use Zerofyi\ShipIt\Tests\Fakes\FakeServer;
use Zerofyi\ShipIt\Tests\TestCase;

class BackupRollbackCommandsTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidEnv();
        $this->server = new FakeServer();
        $this->server->register();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    /** Seed a journal + snapshot pair representing one good deploy. */
    private function seedRestorePoint(string $id = '20261001-120000', string $commit = 'oldcommit123456'): void
    {
        $journal = Journal::append(Journal::empty(), [
            'type' => 'deploy', 'status' => 'success', 'at' => '2026-10-01 12:00:00',
            'branch' => 'main', 'to_commit' => $commit, 'backup_id' => $id,
        ]);
        $this->server->journal = Journal::encode($journal);
        $this->server->snapshots[$id] = [
            'id' => $id,
            'created_at' => '2026-10-01 12:00:00',
            'commit' => $commit,
            'reason' => 'deploy',
            'database' => ['method' => 'mysqldump', 'name' => 'prod_db', 'tables' => 12],
            'files' => ['db_bytes' => 1000, 'storage_bytes' => 2000, 'build_bytes' => 3000, 'has_env' => true, 'has_storage' => true, 'has_build' => true],
        ];
    }

    public function test_backup_command_creates_snapshot_and_journals_it(): void
    {
        $this->artisan('shipit:backup')->assertExitCode(0);

        $this->assertCount(1, $this->server->snapshots);

        $entries = $this->server->journalEntries();
        $last = end($entries);
        $this->assertSame('backup', $last['type']);
        $this->assertSame('success', $last['status']);
        $this->assertNotEmpty($last['backup_id']);
    }

    public function test_backups_list_and_delete(): void
    {
        $this->artisan('shipit:backup')->assertExitCode(0);
        $id = (string) array_key_first($this->server->snapshots);

        $this->artisan('shipit:backups')
            ->expectsOutputToContain($id)
            ->assertExitCode(0);

        $this->artisan('shipit:backups', ['--delete' => $id])
            ->expectsQuestion(" Delete snapshot {$id} from the server permanently? (y/N)", 'y')
            ->assertExitCode(0);

        $this->assertSame([], $this->server->snapshots);
    }

    public function test_rollback_restores_code_and_takes_safety_snapshot_first(): void
    {
        $this->seedRestorePoint();

        $this->artisan('shipit:rollback', ['--to' => '20261001-120000', '--yes' => true])
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranRemoteContaining('git reset --hard'));
        $this->assertTrue($this->server->ranRemoteContaining('oldcommit123456'));
        $this->assertTrue($this->server->ranRemoteContaining('composer install'));

        // A safety snapshot of the pre-rollback state was taken (a second
        // snapshot now exists next to the restore target).
        $this->assertCount(2, $this->server->snapshots);

        $entries = $this->server->journalEntries();
        $rollback = end($entries);
        $this->assertSame('rollback', $rollback['type']);
        $this->assertSame('success', $rollback['status']);
    }

    public function test_rollback_with_db_rejects_wrong_typed_confirmation(): void
    {
        $this->seedRestorePoint();

        $this->artisan('shipit:rollback', ['--to' => '20261001-120000', '--with-db' => true])
            ->expectsQuestion('  To confirm, type the site directory name exactly (shop.com)', 'not-the-site')
            ->assertExitCode(1);

        // Nothing at all was restored.
        $this->assertFalse($this->server->ranRemoteContaining('git reset --hard'));
        $this->assertFalse($this->server->ranRemoteContaining('db-tool.php'));
    }

    public function test_rollback_with_db_and_correct_typed_confirmation_restores_database(): void
    {
        $this->seedRestorePoint();

        $this->artisan('shipit:rollback', ['--to' => '20261001-120000', '--with-db' => true])
            ->expectsQuestion('  To confirm, type the site directory name exactly (shop.com)', 'shop.com')
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranRemoteContaining('db-tool.php'));
        $restoreCmds = array_filter($this->server->remoteRan, fn ($c) => str_contains($c, ' restore '));
        $this->assertNotSame([], $restoreCmds);
    }

    public function test_rollback_with_migration_rollback_uses_artisan_instead_of_db_restore(): void
    {
        $this->seedRestorePoint();

        $this->artisan('shipit:rollback', ['--to' => '20261001-120000', '--yes' => true, '--migrate-rollback' => true])
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranRemoteContaining('migrate:rollback'));
        $dbRestores = array_filter($this->server->remoteRan, fn ($c) => str_contains($c, 'db-tool.php') && str_contains($c, ' restore '));
        $this->assertSame([], $dbRestores);
    }

    public function test_rollback_without_restore_points_fails_cleanly(): void
    {
        $this->artisan('shipit:rollback', ['--yes' => true])->assertExitCode(1);
        $this->assertFalse($this->server->ranRemoteContaining('git reset --hard'));
    }

    public function test_rollback_to_unknown_snapshot_fails(): void
    {
        $this->seedRestorePoint();

        $this->artisan('shipit:rollback', ['--to' => '19990101-000000', '--yes' => true])
            ->assertExitCode(1);

        $this->assertFalse($this->server->ranRemoteContaining('git reset --hard'));
    }
}
