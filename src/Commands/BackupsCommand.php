<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Support\BackupPlan;
use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\RemoteShell;
use Illuminate\Console\Command;

/**
 * shipit:backups — list, delete and prune the snapshots stored for a site.
 */
class BackupsCommand extends BasePushCommand
{
    protected $signature = 'shipit:backups
                            {--site=   : Site profile from config/shipit.php (default: .env values)}
                            {--delete= : Delete one snapshot by id (asks for confirmation)}
                            {--prune   : Delete snapshots beyond the configured retention limit}';

    protected $description = 'List and manage the backup snapshots stored on the server for a site.';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] ShipIt Backups — snapshot manager</>');
        $this->line('');

        $env = $this->resolveSiteOrFail($this->option('site'), 'server');
        if (!$env) {
            return Command::FAILURE;
        }

        $remote = new RemoteShell($env);
        $backups = new BackupService($remote);

        if ($this->option('delete')) {
            return $this->deleteOne($backups, (string) $this->option('delete'));
        }

        if ($this->option('prune')) {
            $deleted = $backups->prune((int) config('shipit.backup.retention', 3));
            $this->line($deleted === []
                ? '<fg=green>[+] Nothing to prune — snapshot count is within the retention limit.</>'
                : '<fg=green>[+] Pruned snapshots: ' . implode(', ', $deleted) . '</>');
            return Command::SUCCESS;
        }

        $snapshots = $backups->listSnapshots();
        if ($snapshots === []) {
            $this->line('<fg=yellow>[!] No snapshots found for ' . $env['site_dir'] . ' yet.</>');
            $this->line('    They are created automatically before every deployment, or manually with: php artisan shipit:backup');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($snapshots as $snap) {
            $m = $snap['manifest'] ?? [];
            $total = (int) ($m['files']['db_bytes'] ?? 0) + (int) ($m['files']['storage_bytes'] ?? 0) + (int) ($m['files']['build_bytes'] ?? 0);
            $rows[] = [
                $snap['id'],
                $m['created_at'] ?? '—',
                isset($m['commit']) && $m['commit'] ? substr((string) $m['commit'], 0, 7) : '—',
                $m['reason'] ?? '—',
                ($m['database']['method'] ?? 'none'),
                BackupPlan::humanSize($total),
            ];
        }

        $this->table(['Snapshot', 'Created', 'Commit', 'Reason', 'Database', 'Size'], $rows);
        $this->line(' Snapshots live on the server at: ' . $remote->snapshotsRoot());
        $this->line(' Retention: keep newest ' . (int) config('shipit.backup.retention', 3) . ' (config/shipit.php). Delete with --delete=<id>, enforce retention with --prune.');
        return Command::SUCCESS;
    }

    private function deleteOne(BackupService $backups, string $id): int
    {
        if (!BackupPlan::isValidSnapshotId($id)) {
            $this->error("[x] '{$id}' is not a valid snapshot id.");
            return Command::FAILURE;
        }

        if (!$this->confirmYN(" Delete snapshot {$id} from the server permanently?", false)) {
            $this->line('Cancelled. Nothing was deleted.');
            return Command::SUCCESS;
        }

        if (!$backups->deleteSnapshot($id)) {
            $this->error("[x] Failed to delete snapshot {$id}.");
            return Command::FAILURE;
        }

        $this->line("<fg=green>[+] Snapshot {$id} deleted.</>");
        return Command::SUCCESS;
    }
}
