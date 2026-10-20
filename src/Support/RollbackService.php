<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Performs a rollback of one site to a snapshot captured earlier:
 * code (git reset to the recorded commit), built assets, optionally the
 * .env, uploaded files and the database, wrapped in maintenance mode.
 *
 * The database is NEVER restored unless the caller explicitly opts in;
 * a snapshot of the current (pre-rollback) state is taken first, so a
 * rollback is itself reversible.
 */
class RollbackService
{
    public function __construct(
        protected RemoteShell $remote,
        protected BackupService $backups,
    ) {}

    /**
     * @param array{
     *   branch?: ?string,
     *   with_db?: bool,
     *   with_migration_rollback?: bool,
     *   with_storage?: bool,
     *   with_env?: bool,
     *   safety_backup?: bool,
     * } $opts
     * @param callable(string):void|null $log
     * @return array{ok:bool, error:?string, safety_id:?string, commit:?string}
     */
    public function restore(string $snapshotId, array $opts = [], ?callable $log = null): array
    {
        $log ??= fn (string $m) => null;

        if (!BackupPlan::isValidSnapshotId($snapshotId)) {
            return ['ok' => false, 'error' => "Invalid snapshot id: {$snapshotId}", 'safety_id' => null, 'commit' => null];
        }

        $snapshot = null;
        foreach ($this->backups->listSnapshots() as $candidate) {
            if ($candidate['id'] === $snapshotId) {
                $snapshot = $candidate;
                break;
            }
        }
        if ($snapshot === null) {
            return ['ok' => false, 'error' => "Snapshot {$snapshotId} not found on the server.", 'safety_id' => null, 'commit' => null];
        }

        $manifest = $snapshot['manifest'] ?? [];
        $targetCommit = $manifest['commit'] ?? null;
        $branch = $opts['branch'] ?? 'main';
        $site = $this->remote->sitePath();
        $snap = $this->remote->snapshotPath($snapshotId);

        // 1. Safety snapshot of the current state (rollback is reversible).
        $safetyId = null;
        if ($opts['safety_backup'] ?? true) {
            $log('Taking a safety snapshot of the current state first...');
            $safety = $this->backups->createSnapshot($log, 'pre-rollback');
            if ($safety['ok']) {
                $safetyId = $safety['id'];
            } else {
                return ['ok' => false, 'error' => 'Safety snapshot failed, rollback aborted: ' . $safety['error'], 'safety_id' => null, 'commit' => $targetCommit];
            }
        }

        $this->backups->appendJournal([
            'type' => 'rollback',
            'status' => 'started',
            'at' => date('Y-m-d H:i:s'),
            'branch' => $branch,
            'to_commit' => $targetCommit,
            'backup_id' => $snapshotId,
            'message' => 'Rollback to snapshot ' . $snapshotId . ($safetyId ? " (safety snapshot: {$safetyId})" : ''),
        ]);

        $error = null;
        $maintenanceDown = false;

        try {
            // 2. Maintenance mode on (best effort: site may be broken).
            $log('Enabling maintenance mode...');
            $down = $this->remote->run('cd ' . escapeshellarg($site) . ' && php artisan down 2>&1', 120);
            $maintenanceDown = $down->successful();

            // 3. Code: return to the recorded commit exactly.
            if ($targetCommit) {
                $log("Restoring code to commit {$targetCommit}...");
                $checkout = $this->remote->run(implode(' && ', [
                    'cd ' . escapeshellarg($site),
                    'git fetch origin 2>&1',
                    'git checkout ' . escapeshellarg($branch) . ' 2>&1',
                    'git reset --hard ' . escapeshellarg($targetCommit) . ' 2>&1',
                ]), 300);
                if (!$checkout->successful()) {
                    throw new \RuntimeException('Git restore failed: ' . trim($checkout->output() . ' ' . $checkout->errorOutput()));
                }
            }

            // 4. Dependencies for that commit.
            $log('Installing dependencies for the restored commit...');
            $composer = $this->remote->run('cd ' . escapeshellarg($site) . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1', 600);
            if (!$composer->successful()) {
                throw new \RuntimeException('Composer install failed: ' . trim($composer->output() . ' ' . $composer->errorOutput()));
            }

            // 5. Built assets captured in the snapshot.
            if (!empty($manifest['files']['has_build'])) {
                $log('Restoring built frontend assets...');
                $assets = $this->remote->run(implode(' && ', [
                    'cd ' . escapeshellarg($site),
                    'rm -rf public/build',
                    'tar -xzf ' . escapeshellarg($snap . '/build.tar.gz'),
                ]), 300);
                if (!$assets->successful()) {
                    throw new \RuntimeException('Asset restore failed: ' . trim($assets->errorOutput() ?: $assets->output()));
                }
            }

            // 6. Optional pieces.
            if (($opts['with_env'] ?? false) && !empty($manifest['files']['has_env'])) {
                $log('Restoring the .env captured in the snapshot...');
                $this->remote->run('cp ' . escapeshellarg($snap . '/env.snapshot') . ' ' . escapeshellarg($site . '/.env'), 60);
            }

            if (($opts['with_storage'] ?? false) && !empty($manifest['files']['has_storage'])) {
                $log('Restoring uploaded files (storage/app)...');
                $storage = $this->remote->run('cd ' . escapeshellarg($site) . ' && tar -xzf ' . escapeshellarg($snap . '/storage.tar.gz'), 600);
                if (!$storage->successful()) {
                    throw new \RuntimeException('Storage restore failed: ' . trim($storage->errorOutput() ?: $storage->output()));
                }
            }

            if (($opts['with_migration_rollback'] ?? false) && !($opts['with_db'] ?? false)) {
                $log('Reversing the last migration batch...');
                $mig = $this->remote->run('cd ' . escapeshellarg($site) . ' && php artisan migrate:rollback --force 2>&1', 300);
                if (!$mig->successful()) {
                    throw new \RuntimeException('Migration rollback failed: ' . trim($mig->output() . ' ' . $mig->errorOutput()));
                }
            }

            if ($opts['with_db'] ?? false) {
                $method = $manifest['database']['method'] ?? 'none';
                if ($method === 'none') {
                    throw new \RuntimeException('This snapshot contains no database dump, so the database cannot be restored from it.');
                }
                $log('Restoring the database from the snapshot dump...');
                $this->backups->uploadDbTool();
                $tool = $this->remote->dataRoot() . '/helpers/db-tool.php';
                $restore = $this->remote->run('php ' . escapeshellarg($tool) . ' restore ' . escapeshellarg($site) . ' ' . escapeshellarg($snap . '/db.sql.gz') . ' 2>&1', 900);
                if (!$restore->successful()) {
                    throw new \RuntimeException('Database restore failed: ' . trim($restore->output() . ' ' . $restore->errorOutput()));
                }
            }

            // 7. Laravel housekeeping for the restored state.
            $log('Rebuilding caches and storage link...');
            $this->remote->run('cd ' . escapeshellarg($site) . ' && (test -L public/storage || php artisan storage:link) && php artisan optimize:clear && php artisan optimize 2>&1', 300);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // 8. Maintenance mode OFF — always attempted, even after a failure.
        if ($maintenanceDown) {
            $log('Disabling maintenance mode...');
            $this->remote->run('cd ' . escapeshellarg($site) . ' && php artisan up 2>&1', 120);
        }

        $this->backups->patchLastJournalEntry([
            'status' => $error === null ? 'success' : 'failed',
            'failed_step' => $error === null ? null : 'rollback',
            'message' => $error,
        ]);

        return ['ok' => $error === null, 'error' => $error, 'safety_id' => $safetyId, 'commit' => $targetCommit];
    }
}
