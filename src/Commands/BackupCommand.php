<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Support\BackupPlan;
use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\RemoteShell;
use Illuminate\Console\Command;

/**
 * shipit:backup — take a snapshot of a site's current production state
 * (database, uploads, built assets, .env, manifest) without deploying.
 */
class BackupCommand extends BasePushCommand
{
    protected $signature = 'shipit:backup
                            {--site= : Site profile from config/shipit.php (default: .env values)}';

    protected $description = 'Snapshot the current production state of a site on the server (database + uploads + assets + .env) without deploying.';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] ShipIt Backup — snapshot production state</>');
        $this->line('');

        $env = $this->resolveSiteOrFail($this->option('site'), 'server');
        if (!$env) {
            return Command::FAILURE;
        }

        $remote = new RemoteShell($env);
        $backups = new BackupService($remote);

        if (!$backups->acquireLock('shipit:backup on ' . gethostname())) {
            $this->error('[x] Another ShipIt operation is already running against this site (deploy lock is held).');
            return Command::FAILURE;
        }

        try {
            $snapshot = $backups->createSnapshot(fn ($m) => $this->line("    {$m}"), 'manual');

            if (!$snapshot['ok']) {
                $backups->appendJournal([
                    'type' => 'backup',
                    'status' => 'failed',
                    'at' => date('Y-m-d H:i:s'),
                    'message' => $snapshot['error'],
                ]);
                $this->error('[x] Backup failed: ' . $snapshot['error']);
                return Command::FAILURE;
            }

            $manifest = $snapshot['manifest'];
            $backups->appendJournal([
                'type' => 'backup',
                'status' => 'success',
                'at' => date('Y-m-d H:i:s'),
                'to_commit' => $manifest['commit'],
                'backup_id' => $snapshot['id'],
            ]);

            $pruned = $backups->prune((int) config('shipit.backup.retention', 3), $snapshot['id']);

            $total = ($manifest['files']['db_bytes'] ?? 0) + ($manifest['files']['storage_bytes'] ?? 0) + ($manifest['files']['build_bytes'] ?? 0);
            $this->line('');
            $this->line("<fg=green;options=bold>[==] Snapshot {$snapshot['id']} created for {$env['site_dir']}</>");
            $this->line('    Commit  : ' . ($manifest['commit'] ?? 'unknown'));
            $this->line('    Database: ' . ($manifest['database']['method'] ?? 'none') . ' (' . BackupPlan::humanSize((int) ($manifest['files']['db_bytes'] ?? 0)) . ', ' . ($manifest['database']['tables'] ?? 0) . ' tables)');
            $this->line('    Uploads : ' . BackupPlan::humanSize((int) ($manifest['files']['storage_bytes'] ?? 0)));
            $this->line('    Assets  : ' . BackupPlan::humanSize((int) ($manifest['files']['build_bytes'] ?? 0)));
            $this->line('    Total   : ' . BackupPlan::humanSize($total));
            if ($pruned !== []) {
                $this->line('    Pruned old snapshots (retention): ' . implode(', ', $pruned));
            }
            return Command::SUCCESS;
        } finally {
            $backups->releaseLock();
        }
    }
}
