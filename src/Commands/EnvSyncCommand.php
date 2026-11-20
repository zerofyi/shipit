<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\EnvFile;
use Zerofyi\ShipIt\Support\EnvProductionWizard;
use Zerofyi\ShipIt\Support\RemoteShell;
use Illuminate\Console\Command;

/**
 * shipit:env — synchronise a site's server .env with the local
 * .env.production, after showing an exact key-by-key diff.
 *
 * Values are never displayed (keys and change kinds only). The current
 * server .env is backed up on the server first, and the change is
 * journaled, so an env sync is auditable and reversible.
 */
class EnvSyncCommand extends BasePushCommand
{
    protected $signature = 'shipit:env
                            {--site= : Site profile from config/shipit.php (default: .env values)}
                            {--yes   : Apply the sync without the confirmation prompt}';

    protected $description = 'Show a key-by-key diff between .env.production and the server .env, then sync them (with backup + journal).';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] ShipIt Env Sync — .env.production -> server .env</>');
        $this->line('');

        $env = $this->resolveSiteOrFail($this->option('site'), 'server');
        if (!$env) {
            return Command::FAILURE;
        }

        $localPath = base_path('.env.production');
        if (!file_exists($localPath)) {
            if (!$this->option('yes') && $this->input->isInteractive()
                && $this->confirmYN(' No local .env.production found. Create it now with the setup wizard?', false)
            ) {
                if (EnvProductionWizard::run($this, $env) !== 'ready') {
                    $this->line('Cancelled — the server .env was not changed.');
                    return Command::SUCCESS;
                }
            } else {
                $this->error('[x] No local .env.production file found at: ' . $localPath);
                $this->line('    Create it with the production values for this site, then run the command again.');
                return Command::FAILURE;
            }
        }
        $localContent = (string) file_get_contents($localPath);
        $local = EnvFile::parse($localContent);

        $remote = new RemoteShell($env);
        $backups = new BackupService($remote);
        $siteEnvPath = $remote->sitePath() . '/.env';

        $serverContent = $remote->getContent($siteEnvPath);
        $server = $serverContent !== null ? EnvFile::parse($serverContent) : [];

        if ($serverContent === null) {
            $this->line('<fg=yellow>[!] The server has no .env yet — the sync will create it from .env.production.</>');
        }

        $diff = EnvFile::diff($local, $server);

        $this->line('');
        $this->info(" Diff for {$env['site_dir']} (values are never shown):");
        foreach ($diff['added'] as $key) {
            $this->line("   + ADD     {$key}");
        }
        foreach ($diff['changed'] as $key) {
            $this->line("   ~ CHANGE  {$key}");
        }
        foreach ($diff['removed'] as $key) {
            $this->line("   - REMOVE  {$key}");
        }
        $this->line("   = {$diff['unchanged']} key(s) already identical");
        $this->line('');

        if ($diff['added'] === [] && $diff['changed'] === [] && $diff['removed'] === []) {
            $this->line('<fg=green>[+] Already in sync — nothing to do.</>');
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirmYN(' Apply this sync to the server .env?', false)) {
            $this->line('Cancelled — the server .env was not changed.');
            return Command::SUCCESS;
        }

        if (!$backups->acquireLock('shipit:env on ' . gethostname())) {
            $this->error('[x] Another ShipIt operation is already running against this site (deploy lock is held).');
            return Command::FAILURE;
        }

        try {
            // Back up the current server .env before touching it.
            $backupPath = null;
            if ($serverContent !== null) {
                $remote->ensureDataRoot();
                $backupPath = $remote->dataRoot() . '/env-backups/' . date('Ymd-His') . '.env';
                $remote->run('mkdir -p ' . escapeshellarg($remote->dataRoot() . '/env-backups'), 60);
                $saved = $remote->putContent($serverContent, $backupPath);
                if (!$saved->successful()) {
                    $this->error('[x] Could not back up the current server .env — sync aborted, nothing changed.');
                    return Command::FAILURE;
                }
                $this->line("    Current server .env backed up to {$backupPath}");
            }

            $uploaded = $remote->putContent($localContent, $siteEnvPath);
            if (!$uploaded->successful()) {
                $this->error('[x] Failed to write the new .env to the server.');
                return Command::FAILURE;
            }

            // Make Laravel pick up the new values immediately.
            $remote->run('cd ' . escapeshellarg($remote->sitePath()) . ' && php artisan config:clear 2>&1', 120);

            $backups->appendJournal([
                'type' => 'env_sync',
                'status' => 'success',
                'at' => date('Y-m-d H:i:s'),
                'message' => 'Env sync: +' . count($diff['added']) . ' ~' . count($diff['changed']) . ' -' . count($diff['removed'])
                    . ($backupPath ? " (previous .env: {$backupPath})" : ''),
            ]);

            $this->line('<fg=green;options=bold>[==] Server .env synchronised for ' . $env['site_dir'] . '.</>');
            return Command::SUCCESS;
        } finally {
            $backups->releaseLock();
        }
    }
}
