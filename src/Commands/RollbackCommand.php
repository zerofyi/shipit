<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\HealthCheck;
use Zerofyi\ShipIt\Support\Journal;
use Zerofyi\ShipIt\Support\RemoteShell;
use Zerofyi\ShipIt\Support\RollbackService;
use Illuminate\Console\Command;

/**
 * shipit:rollback — return a site to a previously snapshotted state.
 *
 * Code and built assets are always restored. Uploads, .env and the
 * database are opt-in; the database restore is destructive (it discards
 * everything written after the snapshot) and ALWAYS requires typing the
 * site directory name as confirmation. Before anything is touched, a
 * safety snapshot of the current state is taken, so a rollback can
 * itself be rolled back.
 */
class RollbackCommand extends BasePushCommand
{
    protected $signature = 'shipit:rollback
                            {--site=             : Site profile from config/shipit.php (default: .env values)}
                            {--to=               : Snapshot id to restore (default: choose interactively)}
                            {--with-storage      : Also restore uploaded files (storage/app) from the snapshot}
                            {--with-env          : Also restore the .env captured in the snapshot}
                            {--with-db           : Also restore the DATABASE from the snapshot (destructive)}
                            {--migrate-rollback  : Instead of a DB restore, reverse the last migration batch only}
                            {--skip-safety       : Skip the safety snapshot of the current state (not recommended)}
                            {--yes               : Skip the general confirmation prompt}';

    protected $description = 'Roll a site back to a previous snapshot: code + assets always, uploads/.env/database on request.';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] ShipIt Rollback — return to a known-good state</>');
        $this->line('');

        $env = $this->resolveSiteOrFail($this->option('site'), 'server');
        if (!$env) {
            return Command::FAILURE;
        }

        $remote = new RemoteShell($env);
        $backups = new BackupService($remote);

        $journal = $backups->readJournal();
        $points = Journal::restorePoints($journal);

        if ($points === []) {
            $this->error('[x] No restore points exist for ' . $env['site_dir'] . ' yet.');
            $this->line('    Restore points are created by deployments (pre-deploy backup) and by: php artisan shipit:backup');
            return Command::FAILURE;
        }

        // Pick the target snapshot.
        $targetId = $this->option('to') ? (string) $this->option('to') : null;
        if ($targetId !== null) {
            $known = array_column($points, 'backup_id');
            if (!in_array($targetId, $known, true)) {
                $this->error("[x] Snapshot {$targetId} is not a known restore point for this site.");
                return Command::FAILURE;
            }
        } else {
            $this->line(' Available restore points (newest first):');
            foreach ($points as $i => $point) {
                $num = $i + 1;
                $commit = $point['commit'] ? substr((string) $point['commit'], 0, 7) : '???????';
                $this->line("   [{$num}] {$point['backup_id']} — {$point['label']} — commit {$commit}");
            }
            $this->line('');
            $answer = $this->ask(' Enter the number of the restore point to roll back to', '1');
            $index = (int) $answer - 1;
            if (!isset($points[$index])) {
                $this->error('[x] Invalid selection. Rollback cancelled.');
                return Command::FAILURE;
            }
            $targetId = $points[$index]['backup_id'];
        }

        // Branch recorded for the deploy that created this restore point.
        $branch = 'main';
        foreach ($journal['entries'] as $entry) {
            if (($entry['backup_id'] ?? null) === $targetId && !empty($entry['branch'])) {
                $branch = $entry['branch'];
            }
        }

        $withDb = (bool) $this->option('with-db');
        $withMigrationRollback = (bool) $this->option('migrate-rollback');

        // Explain exactly what is about to happen.
        $this->line('');
        $this->info(" Rollback plan for {$env['site_dir']}:");
        $this->line("   Snapshot        : {$targetId}");
        $this->line('   Code + assets   : restore');
        $this->line('   Uploaded files  : ' . ($this->option('with-storage') ? 'restore' : 'keep current'));
        $this->line('   .env            : ' . ($this->option('with-env') ? 'restore' : 'keep current'));
        $this->line('   Database        : ' . ($withDb ? 'RESTORE FROM SNAPSHOT (data written after the snapshot will be LOST)' : ($withMigrationRollback ? 'reverse last migration batch' : 'keep current')));
        $this->line('   Safety snapshot : ' . ($this->option('skip-safety') ? 'SKIPPED' : 'taken first (this rollback stays reversible)'));
        $this->line('');

        if ($withDb) {
            $this->error('  DATABASE RESTORE IS DESTRUCTIVE: every order, signup and change');
            $this->error('  written after the snapshot was taken will be permanently lost.');
            $typed = $this->ask("  To confirm, type the site directory name exactly ({$env['site_dir']})");
            if (trim((string) $typed) !== $env['site_dir']) {
                $this->error('[x] Confirmation did not match. Rollback cancelled — nothing was changed.');
                return Command::FAILURE;
            }
        } elseif (!$this->option('yes')) {
            if (!$this->confirmYN(' Proceed with the rollback?', false)) {
                $this->line('Rollback cancelled — nothing was changed.');
                return Command::SUCCESS;
            }
        }

        if (!$backups->acquireLock('shipit:rollback on ' . gethostname())) {
            $this->error('[x] Another ShipIt operation is already running against this site (deploy lock is held).');
            return Command::FAILURE;
        }

        try {
            $service = new RollbackService($remote, $backups);
            $result = $service->restore($targetId, [
                'branch' => $branch,
                'with_db' => $withDb,
                'with_migration_rollback' => $withMigrationRollback,
                'with_storage' => (bool) $this->option('with-storage'),
                'with_env' => (bool) $this->option('with-env'),
                'safety_backup' => !$this->option('skip-safety'),
            ], fn ($m) => $this->line("    {$m}"));

            if (!$result['ok']) {
                $this->error('[x] Rollback failed: ' . $result['error']);
                if ($result['safety_id']) {
                    $this->line("    The pre-rollback state was snapshotted as {$result['safety_id']} — nothing is lost; investigate and try again.");
                }
                return Command::FAILURE;
            }

            $this->line('');
            $this->line('<fg=green;options=bold>[==] Rollback complete. ' . $env['site_dir'] . ' is back on snapshot ' . $targetId . '.</>');
            if ($result['safety_id']) {
                $this->line("    Changed your mind? The state you just left is preserved as snapshot {$result['safety_id']}.");
            }

            if (config('shipit.health_check.enabled', true)) {
                $check = HealthCheck::check('https://' . $env['site_dir'], (int) config('shipit.health_check.timeout', 15));
                $this->line($check['ok']
                    ? "    <fg=green>[+] Health check: https://{$env['site_dir']} -> HTTP {$check['status']}</>"
                    : "    <fg=red>[x] Health check after rollback: " . ($check['error'] ?? 'failed') . '</>');
            }

            return Command::SUCCESS;
        } finally {
            $backups->releaseLock();
        }
    }
}
