<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Commands\BasePushCommand;
use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\EnvProductionWizard;
use Zerofyi\ShipIt\Support\HealthCheck;
use Zerofyi\ShipIt\Support\Journal;
use Zerofyi\ShipIt\Support\RemoteShell;
use Zerofyi\ShipIt\Support\RollbackService;
use Zerofyi\ShipIt\Support\Urls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Exception;

class PushHostinger extends BasePushCommand
{
    protected $signature = 'push:hostinger
                            {--dry-run     : Simulate deployment pipelines without editing server files}
                            {--debug       : Print comprehensive network and command execution outputs}
                            {--site=       : Site profile from config/shipit.php (default: .env values)}
                            {--branch=     : Branch to deploy (default: your current local branch)}
                            {--message=    : Commit message for the GitHub wizard (skips the prompt)}
                            {--timeout=    : Git push timeout window in seconds (default: 60)}
                            {--skip-assets : Skip compiling frontend assets locally}
                            {--skip-backup : Skip the pre-deploy backup snapshot (journal records the skip)}
                            {--only        : Selective deploy — interactively choose which changed files to deploy}
                            {--files=      : Selective deploy — comma-separated list of changed files to deploy}';

    protected $description = 'Pushes updates to GitHub, snapshots the current production state, and completes a deployment to Hostinger via optimized SSH pipelines.';

    private ?RemoteShell $remote = null;
    private ?BackupService $backups = null;
    private bool $lockHeld = false;

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] Initializing Complete Hostinger Pipeline Execution...</>');
        $this->line('');

        try {
            // Phase 1: Parse and Validate environment parameter states
            $env = $this->resolveSiteOrFail($this->option('site'), 'full');
            if (!$env) {
                return Command::FAILURE;
            }

            $sshRepoUrl = Urls::toSshUrl($env['repo_url']);
            $this->remote = new RemoteShell($env);
            $this->backups = new BackupService($this->remote);
            $absolutePath = $this->remote->sitePath();
            $sshBase = $this->remote->sshBase();

            // Branch selection: --branch wins, otherwise the local branch.
            $branch = $this->option('branch') ?: $this->localBranch();
            if ($this->option('branch') && $this->option('branch') !== $this->localBranch()) {
                $this->error("[x] You asked to deploy branch [{$this->option('branch')}] but your local checkout is on [{$this->localBranch()}].");
                $this->line('    Switch branches first (git checkout ' . $this->option('branch') . ') or drop the --branch flag.');
                return Command::FAILURE;
            }

            // Phase 2: SSH Verification handshake (read-only) — before the
            // asset build, so connection problems fail fast instead of
            // after a minute of local compilation.
            $this->line('<fg=cyan>[*] Initializing connection sequence with remote Hostinger node...</>');
            $pathCheck = Process::run("{$sshBase} " . escapeshellarg("test -d '{$absolutePath}' && echo 'exists' || echo 'missing'"));

            if (!$pathCheck->successful()) {
                $this->line('');
                $this->error("[x] Connection Failure: Could not establish a secure handshake with your Hostinger server.");
                $this->line('<fg=yellow>[!] Troubleshooting Steps:</>');
                $this->line('    1. Verify that "SSH Access" is explicitly ENABLED inside your Hostinger Control Panel.');
                $this->line('    2. Confirm that your local public SSH key has been added to Hostinger\'s "Authorized Keys".');
                $this->line('    3. Verify that your .env SSH configurations (Host, Username, Port) match exactly.');
                $this->line('    4. Hostinger\'s SSH port is usually 65002, not 22 — double-check HOSTINGER_SSH_PORT.');
                return Command::FAILURE;
            }

            if (trim($pathCheck->output()) !== 'exists') {
                $this->line('');
                $this->error("[x] Error: Target deployment directory [{$absolutePath}] does not exist on your Hostinger server.");
                $this->line('    Please map and set up this domain directory correctly within your Hostinger control panel first.');
                return Command::FAILURE;
            }

            $this->line('<fg=green>[+] Production target directory path verified.</>');

            // Phase 3: First-deploy .env.production wizard — only when the
            // server has no .env yet and there is no local .env.production
            // to upload. Runs before assets, git push and backup, so a
            // stopped wizard leaves nothing half-deployed.
            if ($this->serverEnvMissing($sshBase, $absolutePath)) {
                $wizard = EnvProductionWizard::run($this, $env, $isDryRun);
                if ($wizard === 'stopped') {
                    $this->error('[x] Deployment stopped — .env.production was not created.');
                    return Command::FAILURE;
                }
                if ($wizard === 'deferred' && !$isDryRun) {
                    $this->error('[x] No local .env.production and no terminal to create one in.');
                    $this->line('    Create it first (php artisan shipit:doctor can walk you through it), then deploy again.');
                    return Command::FAILURE;
                }
            }

            // Phase 4: Compile Frontend Assets Locally (Built exactly ONCE)
            if (!$this->option('skip-assets')) {
                if (!$this->compileFrontendAssetsLocally()) {
                    return Command::FAILURE;
                }
            }

            // Phase 5: Take the per-site deploy lock (real runs only)
            if (!$isDryRun) {
                if (!$this->backups->acquireLock('push:hostinger on ' . gethostname())) {
                    $this->error('[x] Another ShipIt operation is already running against this site (deploy lock is held).');
                    $this->line('    If you are sure nothing is running, the lock expires automatically after 2 hours.');
                    return Command::FAILURE;
                }
                $this->lockHeld = true;
            }

            try {
                return $this->runPipeline($env, $sshRepoUrl, $branch, $isDryRun);
            } finally {
                if ($this->lockHeld) {
                    $this->backups->releaseLock();
                    $this->lockHeld = false;
                }
            }
        } catch (Exception $e) {
            $this->line('');
            $this->error('[x] Fatal unhandled exception terminated the deployment pipeline: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function localBranch(): string
    {
        $branchCheck = Process::run('git branch --show-current');
        return ($branchCheck->successful() && !empty(trim($branchCheck->output()))) ? trim($branchCheck->output()) : 'main';
    }

    /**
     * Is the server .env missing? Returns false when the check itself
     * cannot run — the later provisioning phase handles that case as
     * before, instead of the wizard firing on a guess.
     */
    private function serverEnvMissing(string $sshBase, string $absolutePath): bool
    {
        $check = Process::run("{$sshBase} " . escapeshellarg("test -f '{$absolutePath}/.env' && echo 'exists' || echo 'missing'"));
        if (!$check->successful()) {
            $this->debug('Server .env check did not run — leaving env provisioning to the deploy phase.');
            return false;
        }
        return trim($check->output()) === 'missing';
    }

    private function runPipeline(array $env, string $sshRepoUrl, string $branch, bool $isDryRun): int
    {
        $absolutePath = $this->remote->sitePath();
        $sshBase = $this->remote->sshBase();

        // Snapshot of server state BEFORE this deploy (for journal + rollback offers)
        $preJournal = $isDryRun ? Journal::empty() : $this->backups->readJournal();
        $previousRestorePoint = Journal::restorePoints($preJournal)[0] ?? null;
        $serverHead = null;
        if (!$isDryRun) {
            $head = $this->remote->run("cd '{$absolutePath}' && git rev-parse HEAD 2>/dev/null", 60);
            $serverHead = $head->successful() ? (trim($head->output()) ?: null) : null;
        }
        $fromCommit = $serverHead ?: Journal::currentKnownCommit($preJournal);

        // Phase 6: Route Sub-Wizard task loops to manage Git configurations
        $this->line('<fg=yellow>[o] Routing tasks into local Git setup wizard...</>');
        $gitWizardCode = $this->call('push:github', array_filter([
            '--dry-run' => $isDryRun,
            '--skip-assets' => true,
            '--debug' => (bool) $this->option('debug'),
            '--site' => $this->option('site'),
            '--message' => $this->option('message'),
            '--timeout' => $this->option('timeout'),
            '--only' => (bool) $this->option('only'),
            '--files' => $this->option('files'),
        ], fn ($v) => $v !== null && $v !== false));

        if ($gitWizardCode !== 0) {
            $this->error('[x] Deployment aborted. Core structural codebase syncing failed.');
            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->line('<fg=yellow>[!] [DRY RUN] Backup, journal, trust setup and the remote deployment would run next. Nothing on the server was touched.</>');
            $this->line('');
            $this->line('<fg=green;options=bold>[==] Dry run finished. No changes were made anywhere.</>');
            return Command::SUCCESS;
        }

        $toCommit = null;
        $headResult = Process::run('git rev-parse HEAD');
        if ($headResult->successful()) {
            $toCommit = trim($headResult->output()) ?: null;
        }

        $isPartial = $this->option('only') || !empty($this->option('files'));
        $skipBackup = (bool) $this->option('skip-backup') || !(bool) config('shipit.backup.enabled', true);

        // Phase 7: Journal the deploy, then snapshot production BEFORE changing it
        $this->backups->appendJournal([
            'type' => 'deploy',
            'status' => 'started',
            'at' => date('Y-m-d H:i:s'),
            'branch' => $branch,
            'from_commit' => $fromCommit,
            'to_commit' => $toCommit,
            'partial' => (bool) $isPartial,
            'files' => $this->parseFilesOption($this->option('files')),
            'backup_skipped' => $skipBackup,
        ]);

        $backupId = null;
        if ($skipBackup) {
            $this->line('<fg=yellow>[!] Pre-deploy backup skipped' . ($this->option('skip-backup') ? ' (--skip-backup)' : ' (disabled in config)') . '. The journal records this deploy as unprotected.</>');
        } else {
            $this->line('<fg=cyan>[*] Creating pre-deploy backup snapshot of the current production state...</>');
            $snapshot = $this->backups->createSnapshot(fn ($m) => $this->line("    {$m}"), 'deploy');
            if (!$snapshot['ok']) {
                $this->backups->patchLastJournalEntry(['status' => 'failed', 'failed_step' => 'backup', 'message' => $snapshot['error']]);
                $this->error('[x] Pre-deploy backup failed — deployment aborted BEFORE anything on the server changed.');
                $this->line('    ' . $snapshot['error']);
                $this->line('    Fix the backup problem (run: php artisan shipit:doctor) or deploy with --skip-backup if you accept the risk.');
                return Command::FAILURE;
            }
            $backupId = $snapshot['id'];
            $this->backups->patchLastJournalEntry(['backup_id' => $backupId]);
            $this->line("<fg=green>[+] Backup snapshot [{$backupId}] secured. Rollback point created.</>");
        }

        // Phase 8: Server-to-GitHub Trust Verification Engine
        $finalServerRepoUrl = $this->resolveServerToGitHubTrust($env['repo_url'], $sshRepoUrl, $sshBase, $isDryRun);
        if (!$finalServerRepoUrl) {
            $this->backups->patchLastJournalEntry(['status' => 'failed', 'failed_step' => 'trust', 'message' => 'Server-to-GitHub trust could not be established.']);
            return Command::FAILURE;
        }

        // Phase 9: Pre-deploy hooks (a failing hook aborts the deployment)
        foreach ((array) config('shipit.hooks.before', []) as $hook) {
            $this->line("<fg=yellow>[o] Running pre-deploy hook: {$hook}</>");
            $hookResult = $this->remote->run("cd '{$absolutePath}' && {$hook}", self::PROCESS_TIMEOUT);
            if (!$hookResult->successful()) {
                $this->backups->patchLastJournalEntry(['status' => 'failed', 'failed_step' => 'hook:before', 'message' => $hook]);
                $this->error("[x] Pre-deploy hook failed and aborted the deployment: {$hook}");
                $this->printFormattedOutput('Hook Error Output', $hookResult->errorOutput() ?: $hookResult->output());
                return Command::FAILURE;
            }
        }

        // Phase 10: Code Syncing & Production Pipeline Optimization Execution
        $deployment = $this->executeRemoteDeployment($finalServerRepoUrl, $sshBase, $absolutePath, $env, $branch);
        if (!$deployment['ok']) {
            $this->backups->patchLastJournalEntry([
                'status' => 'failed',
                'failed_step' => $deployment['failed_step'],
                'message' => 'Deployment failed at step: ' . $deployment['failed_step'],
            ]);
            $this->printRecoveryReport($deployment, $backupId, $branch);
            return Command::FAILURE;
        }

        // Phase 11: Post-deploy hooks (warnings only — the deploy already succeeded)
        foreach ((array) config('shipit.hooks.after', []) as $hook) {
            $this->line("<fg=yellow>[o] Running post-deploy hook: {$hook}</>");
            $hookResult = $this->remote->run("cd '{$absolutePath}' && {$hook}", self::PROCESS_TIMEOUT);
            if (!$hookResult->successful()) {
                $this->line("<fg=yellow>[!] Post-deploy hook failed (deployment itself succeeded): {$hook}</>");
                $this->printFormattedOutput('Hook Error Output', $hookResult->errorOutput() ?: $hookResult->output());
            }
        }

        // Phase 12: Journal success, prune old snapshots, optional local download
        $this->backups->patchLastJournalEntry(['status' => 'success']);
        if ($backupId) {
            $this->backups->prune((int) config('shipit.backup.retention', 3), $backupId);
            if (config('shipit.backup.download', false)) {
                $this->downloadSnapshot($backupId);
            }
        }

        // Phase 13: Post-deploy health check (+ instant rollback offer)
        if (config('shipit.health_check.enabled', true)) {
            $this->runHealthCheckWithRollbackOffer($env, $branch, $previousRestorePoint);
        }

        $this->line('');
        $this->line("<fg=green;options=bold>[==] Deployment successfully updated! Production live link: https://{$env['site_dir']}</>");
        return Command::SUCCESS;
    }

    /**
     * Verify the site answers HTTP successfully after the deployment.
     * On failure, offer an immediate rollback to the previous deploy.
     */
    private function runHealthCheckWithRollbackOffer(array $env, string $branch, ?array $previousRestorePoint): void
    {
        $urls = ['https://' . $env['site_dir']];
        foreach ((array) config('shipit.health_check.urls', []) as $path) {
            $urls[] = 'https://' . $env['site_dir'] . '/' . ltrim((string) $path, '/');
        }

        $this->line('<fg=cyan>[*] Running post-deploy health check...</>');
        $failures = [];
        foreach ($urls as $url) {
            $check = HealthCheck::check($url, (int) config('shipit.health_check.timeout', 15));
            if ($check['ok']) {
                $this->line("    <fg=green>[+] {$url} -> HTTP {$check['status']}</>");
            } else {
                $this->line("    <fg=red>[x] {$url} -> " . ($check['error'] ?? 'failed') . '</>');
                $failures[] = $url;
            }
        }

        if ($failures === []) {
            return;
        }

        $this->line('');
        $this->error('[x] Health check FAILED after deployment. The new release may be broken in production.');

        if ($previousRestorePoint && !empty($previousRestorePoint['backup_id'])) {
            $this->line("    Previous known-good state: snapshot {$previousRestorePoint['backup_id']} ({$previousRestorePoint['at']})");
            if ($this->confirmYN(' Roll back to the previous deploy right now?', false)) {
                $service = new RollbackService($this->remote, $this->backups);
                $result = $service->restore($previousRestorePoint['backup_id'], ['branch' => $branch], fn ($m) => $this->line("    {$m}"));
                if ($result['ok']) {
                    $this->line('<fg=green>[+] Rollback complete. The site is back on the previous release.</>');
                } else {
                    $this->error('[x] Automatic rollback failed: ' . $result['error']);
                    $this->line('    Recover manually: php artisan shipit:rollback' . ($this->option('site') ? ' --site=' . $this->option('site') : ''));
                }
                return;
            }
        }

        $this->line('    To roll back manually at any time: php artisan shipit:rollback' . ($this->option('site') ? ' --site=' . $this->option('site') : ''));
    }

    /** Best-effort download of the fresh snapshot to the local machine. */
    private function downloadSnapshot(string $backupId): void
    {
        $base = (string) config('shipit.backup.download_path', storage_path('app/shipit-backups'));
        $localDir = $base . '/' . $this->env['site_dir'] . '/' . $backupId;
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }
        $remoteDir = $this->remote->snapshotPath($backupId);
        $target = "{$this->remote->sshTarget()}:{$remoteDir}/";
        $cmd = $this->remote->scpBase() . ' -r ' . escapeshellarg($target) . ' ' . escapeshellarg($localDir);
        $result = Process::timeout(900)->run($cmd);
        if ($result->successful()) {
            $this->line("<fg=green>[+] Snapshot also downloaded locally to {$localDir}</>");
        } else {
            $this->line('<fg=yellow>[!] Local snapshot download failed (the server-side snapshot is safe). Check shipit.backup.download_path.</>');
        }
    }

    /**
     * When a deployment dies mid-pipeline, tell the user exactly where
     * things stand and exactly how to recover — no guessing.
     */
    private function printRecoveryReport(array $deployment, ?string $backupId, string $branch): void
    {
        $this->line('');
        $this->error(' ══════════════════════════════════════════════════════════════');
        $this->error('  DEPLOYMENT FAILED — RECOVERY REPORT');
        $this->error(' ══════════════════════════════════════════════════════════════');
        $this->line('  Site          : ' . $this->env['site_dir']);
        $this->line('  Failed at step: ' . ($deployment['failed_step'] ?? 'unknown'));
        $this->line('  Steps already completed on the server:');
        foreach (($deployment['steps'] ?? []) as $step) {
            $this->line("    - {$step}");
        }
        if (($deployment['steps'] ?? []) === []) {
            $this->line('    - (none)');
        }
        $this->line('  Migrations ran: ' . (($deployment['migrations_ran'] ?? false) ? 'YES — database schema may have changed' : 'no'));
        $this->line('  Maintenance mode: ' . (($deployment['maintenance_left_on'] ?? false) ? 'may still be ON — the site shows the maintenance page' : 'off'));
        if ($backupId) {
            $this->line("  Pre-deploy backup: snapshot {$backupId} is stored safely on the server.");
            $this->line('');
            $this->line('  TO RECOVER, run:');
            $this->line('    php artisan shipit:rollback' . ($this->option('site') ? ' --site=' . $this->option('site') : ''));
        } else {
            $this->line('  Pre-deploy backup: NONE (backup was skipped for this deploy).');
            $this->line('  Recovery options: fix the failing step and deploy again, or restore');
            $this->line('  an older snapshot with: php artisan shipit:rollback' . ($this->option('site') ? ' --site=' . $this->option('site') : ''));
        }
        $this->error(' ══════════════════════════════════════════════════════════════');
        $this->line('');
    }

    private function resolveServerToGitHubTrust(string $httpRepoUrl, string $sshRepoUrl, string $sshBase, bool $isDryRun): ?string
    {
        $host = Urls::hostOf($sshRepoUrl);

        if ($isDryRun) {
            $this->info('[DRY RUN] Skip trust layer evaluation.');
            return $httpRepoUrl;
        }

        // 1. Force feed hostname signatures safely into remote known_hosts mapping
        $this->line("<fg=yellow>[o] Synchronizing host keys matching destination domain signature: {$host}</>");
        $scanCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && if ! grep -q '{$host}' ~/.ssh/known_hosts 2>/dev/null; then ssh-keyscan -H '{$host}' >> ~/.ssh/known_hosts 2>/dev/null; fi");
        Process::run($scanCmd);

        // 2. Intercept repository configuration profile context visibility variables
        $this->line('<fg=yellow>[o] Resolving repository accessibility profile context...</>');

        $visibilityCheck = Process::env([
            'GITHUB_TOKEN'        => null,
            'GIT_ASKPASS'         => 'echo',
            'GIT_TERMINAL_PROMPT' => '0'
        ])->run('git -c credential.helper= ls-remote -h ' . escapeshellarg($httpRepoUrl));

        if ($visibilityCheck->successful()) {
            $this->line('<fg=green>[+] Public repository signature detected. Skipping authentication setup steps.</>');
            return $httpRepoUrl;
        }

        $this->line('<fg=magenta>[!] Private repository detected. Managing deployment keys on the server...</>');

        // 3. Check for existing server keys or generate an unpassphrased profile dynamically via RSA
        $keyCheckCmd = "{$sshBase} " . escapeshellarg("test -f ~/.ssh/id_rsa && echo 'exists' || echo 'missing'");
        $keyCheck = trim(Process::run($keyCheckCmd)->output());

        $keyPath = '~/.ssh/id_rsa';
        if ($keyCheck === 'missing') {
            $this->line('<fg=cyan>[*] Key files absent on host server. Generating fresh unpassphrased RSA 4096-bit key pair...</>');

            $genCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && ssh-keygen -t rsa -b 4096 -P '' -f ~/.ssh/id_rsa");
            $genProcess = Process::run($genCmd);

            if (!$genProcess->successful()) {
                $this->error('[x] Failed to execute key generation command on Hostinger.');
                $this->printFormattedOutput('Keygen Error Output', $genProcess->errorOutput());
                return null;
            }
        }

        // Fetch public key footprint output string directly from the host filesystem
        $getPubCmd = "{$sshBase} " . escapeshellarg("cat {$keyPath}.pub");
        $publicKey = trim(Process::run($getPubCmd)->output());

        if (empty($publicKey)) {
            $this->error('[x] Failed to retrieve structural public key string from server configuration context.');
            return null;
        }

        // Normalize Public Key: Strip host trail comments to fulfill precise API payload checks
        $keyParts = explode(' ', $publicKey);
        $normalizedKey = (count($keyParts) >= 2) ? $keyParts[0] . ' ' . $keyParts[1] : $publicKey;

        // 4. Inject public key data string directly into GitHub Repository configurations if a token is readily present
        $token = $this->env['github_token'] ?? null;
        if (!empty($token)) {
            $this->line('<fg=cyan>[*] GITHUB_API_TOKEN found. Attempting automatic Deploy Key injection...</>');

            $ownerRepo = Urls::ownerRepo($sshRepoUrl);
            if ($ownerRepo !== null) {
                [$owner, $repoName] = $ownerRepo;

                $apiUrl = "https://api.github.com/repos/{$owner}/{$repoName}/keys";
                $compiledHttpsTokenUrl = "https://x-access-token:" . trim((string) $token) . "@github.com/{$owner}/{$repoName}.git";

                try {
                    $checkResponse = Http::withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim((string) $token),
                    ])->get($apiUrl);

                    $alreadyLinked = false;
                    if ($checkResponse->successful()) {
                        foreach ((array) $checkResponse->json() as $existingKey) {
                            $exParts = explode(' ', $existingKey['key']);
                            $normalizedExKey = (count($exParts) >= 2) ? $exParts[0] . ' ' . $exParts[1] : $existingKey['key'];
                            if ($normalizedExKey === $normalizedKey) {
                                $alreadyLinked = true;
                                break;
                            }
                        }
                    }

                    if ($alreadyLinked) {
                        $this->line('<fg=green>[+] Deploy key already recognized active on GitHub.</>');
                        return $sshRepoUrl;
                    }

                    $response = Http::timeout(15)->withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim((string) $token),
                    ])->post($apiUrl, [
                        'title' => 'Hostinger Server Deployment Key',
                        'key' => trim($normalizedKey),
                        'read_only' => true
                    ]);

                    if ($response->successful()) {
                        $this->line('<fg=green>[+] Remote security trust chain verified (Deploy key active).</>');
                        return $sshRepoUrl;
                    }

                    // RESCUE PLAN INTERCEPT: Fallback to token HTTPS if key registration hits a 422/403 constraint
                    if ($response->status() === 422 || $response->status() === 403) {
                        $this->line('');
                        $this->line('<fg=yellow>[!] GitHub Deployment Key Collision: This server public key is already in use by another repository.</>');

                        $this->line('<fg=cyan>    Tip: Your GITHUB_API_TOKEN must have "Contents: Read-Only" permissions too, to execute this fallback.</>');
                        $this->line('<fg=cyan>       If it fails with a 403 error, delete your old token, generate a fresh one with Administration: Read & Write</>');
                        $this->line('<fg=cyan>       and Contents: Read-Only permissions, then swap it into your local .env configuration.</>');

                        if ($this->confirmYN(' Would you like ShipIt to execute a token-based HTTPS rescue fallback instead?', true)) {
                            $this->line('<fg=cyan>[*] Activating automated token-based HTTPS rescue fallback...</>');
                            $this->line('<fg=yellow>    Note: the token is used only for this sync. ShipIt removes it from the server\'s git configuration immediately afterwards.</>');

                            return $compiledHttpsTokenUrl;
                        }
                    }

                } catch (Exception $e) {
                    $this->line('');
                    $this->line('<fg=yellow>[!] Automated token API registration handshake timed out or encountered an exception.</>');

                    if ($this->confirmYN(' Would you like ShipIt to execute a token-based HTTPS rescue fallback instead?', true)) {
                        $this->line('<fg=cyan>[*] Activating automated token-based HTTPS rescue fallback...</>');
                        $this->line('<fg=yellow>    Note: the token is used only for this sync. ShipIt removes it from the server\'s git configuration immediately afterwards.</>');

                        return $compiledHttpsTokenUrl;
                    }
                }

                $this->line('<fg=yellow>[!] Automated authentication registration failed. Reverting to manual fallback mode.</>');
            }
        }

        // Interactive manual key exchange console card layout
        $this->line('');
        $this->line('<fg=yellow>[!] Action Required: Please append this server public key to your repository Deploy Keys:</>');
        $this->line("    -> Navigate to: GitHub Repository -> Settings -> Deploy keys");
        $this->line('    -> Click "Add deploy key", name it, paste the string below, and leave "Allow write access" UNCHECKED.');
        $this->line('');
        $this->line(str_repeat('-', 70));
        $this->info($publicKey);
        $this->line(str_repeat('-', 70));
        $this->line('');

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->confirmYN("Press ENTER after you have saved this deploy key to GitHub to continue execution (Attempt {$attempt}/{$maxAttempts})", true)) {
                $this->error('[x] Deployment cancelled by user option.');
                return null;
            }

            $this->line('<fg=cyan>[*] Testing remote server authentication credentials against GitHub...</>');
            $testCmd = "{$sshBase} " . escapeshellarg("ssh -T -o StrictHostKeyChecking=accept-new git@{$host} 2>&1");
            $testProcess = Process::run($testCmd);
            $testOutput = strtolower($testProcess->output() . ' ' . $testProcess->errorOutput());

            if (str_contains($testOutput, 'successfully authenticated') || preg_match('/^hi .+!/m', $testOutput)) {
                $this->line('<fg=green>[+] Key handshakes mapped successfully!</>');
                return $sshRepoUrl;
            }

            if ($attempt < $maxAttempts) {
                $this->line('<fg=yellow>[!] GitHub rejected the connection. Double-check that the key is saved correctly.</>');
            }
        }

        $this->error('[x] Maximum key check attempts exhausted. Aborting deployment.');
        return null;
    }

    /**
     * @return array{ok:bool, failed_step:?string, steps:string[], migrations_ran:bool, maintenance_left_on:bool}
     */
    private function executeRemoteDeployment(string $repoUrl, string $sshBase, string $absolutePath, array $env, string $branch): array
    {
        $state = ['ok' => false, 'failed_step' => null, 'steps' => [], 'migrations_ran' => false, 'maintenance_left_on' => false];

        // 1. Synchronize application codebase layout via Git updates
        $this->line('<fg=yellow>[o] Synchronizing codebase versions on server target... [Processing]</>');
        $repoStatusCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}/.git' && echo 'pull' || echo 'clone'");
        $repoStatus = trim(Process::run($repoStatusCmd)->output());

        if ($repoStatus === 'clone') {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git clone -b {$branch} {$repoUrl} .");
        } else {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git remote set-url origin {$repoUrl} && git fetch origin && git checkout {$branch} && git pull origin {$branch}");
        }

        $syncProcess = Process::timeout(self::PROCESS_TIMEOUT)->run($syncCmd);
        if (!$syncProcess->successful()) {
            $this->error('[x] Codebase alignment step failed.');
            $this->printFormattedOutput('Sync Error Output', $syncProcess->errorOutput());
            $state['failed_step'] = 'code-sync';
            return $state;
        }
        $this->line('    -> Codebase successfully synced.');
        $state['steps'][] = 'Code synced from GitHub';

        // 1b. SECURITY: if the sync used a token-embedded URL (rescue
        // fallback), scrub the token out of the server's .git/config so it
        // does not persist on shared hosting. The next deploy's trust phase
        // re-provisions access before every sync, so this breaks nothing.
        if (Urls::containsCredentials($repoUrl)) {
            $cleanUrl = Urls::stripCredentials($repoUrl);
            $scrub = Process::run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git remote set-url origin {$cleanUrl}"));
            if ($scrub->successful()) {
                $this->line('    <fg=green>[+] Access token removed from the server git configuration.</>');
            } else {
                $this->line('<fg=yellow>[!] WARNING: could not scrub the token from the server git configuration. Remove it manually: git remote set-url origin ' . $cleanUrl . '</>');
            }
        }

        // 2. Synchronize frontend bundle directories — ATOMIC swap edition:
        // the new build is extracted to a staging directory first and only
        // moved into place when complete, so a broken stream can never
        // leave production without assets.
        if (is_dir(base_path('public/build'))) {
            $this->line('<fg=yellow>[o] Delivering compiled frontend bundles via compressed stream pipeline... [Processing]</>');

            $stageDir = "{$absolutePath}/.shipit-stage";
            $swapScript = implode(' && ', [
                "mkdir -p '{$stageDir}'",
                "tar -xzf - -C '{$stageDir}'",
                "mkdir -p '{$absolutePath}/public'",
                "rm -rf '{$absolutePath}/public/build.old'",
                "if [ -d '{$absolutePath}/public/build' ]; then mv '{$absolutePath}/public/build' '{$absolutePath}/public/build.old'; fi",
                "mv '{$stageDir}/build' '{$absolutePath}/public/build'",
                "rm -rf '{$stageDir}' '{$absolutePath}/public/build.old'",
            ]);

            $tarCmd = sprintf('tar -czf - -C ./public build | %s %s', $sshBase, escapeshellarg($swapScript));

            $this->debug("Running Asset Sync Command: {$tarCmd}");
            $tarProcess = Process::run($tarCmd);

            if (!$tarProcess->successful()) {
                // Clean the staging area; the previous build is untouched.
                Process::run("{$sshBase} " . escapeshellarg("rm -rf '{$stageDir}'"));
                $this->error('[x] Asset synchronization pipeline failed. The previous production assets were left untouched.');
                $this->printFormattedOutput('Asset Stream Failure Logs', $tarProcess->errorOutput());
                $state['failed_step'] = 'assets';
                return $state;
            }

            $this->line('    -> Frontend assets synchronized successfully.');
            $state['steps'][] = 'Frontend assets updated (atomic swap)';
        }

        // 3. Verify / Provision Server Environment Configuration (.env)
        $this->line('<fg=yellow>[o] Checking remote environment configuration state...</>');
        $envExistsCmd = "{$sshBase} " . escapeshellarg("test -f '{$absolutePath}/.env' && echo 'exists' || echo 'missing'");
        $envCheck = trim(Process::run($envExistsCmd)->output());

        if ($envCheck === 'missing') {
            $localEnvProd = base_path('.env.production');

            if (file_exists($localEnvProd)) {
                $this->line('<fg=cyan>[*] Local .env.production file detected. Uploading to server...</>');

                $scpCmd = sprintf(
                    '%s %s %s',
                    $this->remote->scpBase(),
                    escapeshellarg($localEnvProd),
                    escapeshellarg("{$this->remote->sshTarget()}:{$absolutePath}/.env")
                );

                $scpProcess = Process::run($scpCmd);

                if ($scpProcess->successful()) {
                    $this->generateAppKeyIfMissing($sshBase, $absolutePath);
                    $this->line('    <fg=green>[+] Production .env uploaded and application key verified.</>');
                } else {
                    $this->line('<fg=yellow>[!] SCP transfer of .env.production failed. Falling back to .env.example...</>');
                    $envCheck = 'fallback';
                }
            } else {
                $envCheck = 'fallback';
            }

            if ($envCheck === 'fallback') {
                $this->line('<fg=cyan>[*] Initializing remote .env from .env.example...</>');
                Process::run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && cp .env.example .env"));
                $this->generateAppKeyIfMissing($sshBase, $absolutePath);

                $this->line('');
                $this->line('<fg=cyan>====================================================================</>');
                $this->line('<fg=yellow>  ACTION REQUIRED: PRODUCTION DATABASE CONFIGURATION NEEDED</>');
                $this->line('<fg=cyan>====================================================================</>');
                $this->line("  1. Open your Hostinger File Manager or SSH into: <fg=green>{$absolutePath}/.env</>");
                $this->line('  2. Configure your DB_DATABASE, DB_USERNAME, and DB_PASSWORD credentials.');
                $this->line('<fg=cyan>====================================================================</>');
                $this->line('');

                if (!$this->confirmYN(' Press ENTER once you have configured your production .env on the server to proceed with migrations', true)) {
                    $this->error('[x] Deployment halted by user prior to database migrations.');
                    $state['failed_step'] = 'env-provisioning';
                    return $state;
                }
            }
            $state['steps'][] = 'Server .env provisioned';
        } else {
            $this->line('    <fg=green>[+] Preserving existing server .env configuration.</>');
            $state['steps'][] = 'Server .env preserved';
        }

        // 4. Complete remote Laravel deployment optimization framework
        // (Strict Circuit-Breaker Loop). Maintenance mode is enabled right
        // before migrations and is ALWAYS brought back down afterwards —
        // including when a critical step trips the circuit breaker.
        $this->line('<fg=cyan>[*] Running production optimization pipeline over SSH...</>');

        $remoteCommands = [
            "Ensure App Directory Context" => "cd '{$absolutePath}'",
            "Install Dependencies"          => "cd '{$absolutePath}' && composer install --no-dev --optimize-autoloader --no-interaction",
            "Run Migrations"               => "cd '{$absolutePath}' && php artisan migrate --force",

            // Intelligent real-time decision-making with output capturing
            "Setup Storage Link"           => "cd '{$absolutePath}' && " .
                                              "if [ -L public/storage ]; then " .
                                              "    echo 'INFO: A symbolic link already exists at public/storage. Skipping creation.'; " .
                                              "elif [ -d public/storage ]; then " .
                                              "    echo 'WARNING: A physical directory already exists at public/storage! Laravel needs this path to be clear to map the link.'; " .
                                              "else " .
                                              "    echo 'ACTION: No existing link found. Running fresh artisan storage:link command now...'; " .
                                              "    php artisan storage:link 2>&1; " .
                                              "fi",

            "Setup Public HTML Symlink"    => "cd '{$absolutePath}' && rm -rf public_html && ln -sfn public public_html",
            "Clear Optimization Cache"     => "cd '{$absolutePath}' && php artisan optimize:clear",
            "Warm Production Cache"        => "cd '{$absolutePath}' && php artisan optimize",
            "Restart Queue Workers"        => "cd '{$absolutePath}' && php artisan queue:restart",
        ];

        $nonCriticalTasks = [
            "Setup Storage Link",
            "Setup Public HTML Symlink",
            "Clear Optimization Cache",
            "Warm Production Cache",
            "Restart Queue Workers",
        ];

        $manualFixSuggestions = [
            "Setup Storage Link"         => "Create the symlink manually using raw Linux streams: ln -sfn ../storage/app/public public/storage",
            "Setup Public HTML Symlink"  => "Link your public folder to the web root folder manually: ln -sfn public public_html",
            "Clear Optimization Cache"   => "Clear your application cache manually directly on the host: php artisan cache:clear",
            "Warm Production Cache"      => "Optimize your framework configurations manually: php artisan optimize",
            "Restart Queue Workers"      => "Restart queue workers manually: php artisan queue:restart (only relevant when workers run)",
        ];

        $maintenanceDown = false;

        foreach ($remoteCommands as $taskName => $commandString) {
            // Visitors see the maintenance page — not a half-migrated app —
            // while the database schema is being changed.
            if ($taskName === 'Run Migrations' && !$maintenanceDown) {
                $this->line('<fg=yellow>    ... Enabling maintenance mode for the migration window ...</>');
                $downProcess = Process::timeout(120)->run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && php artisan down"));
                if ($downProcess->successful()) {
                    $maintenanceDown = true;
                    $state['steps'][] = 'Maintenance mode enabled';
                } else {
                    $this->line('<fg=yellow>    [!] Could not enable maintenance mode (continuing without it):</>');
                    $this->printFormattedOutput('Maintenance Mode Output', $downProcess->errorOutput() ?: $downProcess->output());
                }
            }

            $this->debug("Executing task: {$taskName}");
            $this->line("<fg=yellow>    ... Processing: {$taskName} ...</>");

            $execCmd = "{$sshBase} " . escapeshellarg($commandString);
            $process = Process::timeout(self::PROCESS_TIMEOUT)->run($execCmd);

            $errorLog = !empty($process->errorOutput()) ? $process->errorOutput() : $process->output();
            $outputTrimmed = trim($process->output());

            $hasInternalError = (in_array($taskName, $nonCriticalTasks) && str_contains($outputTrimmed, 'Call to undefined function'));

            if (!$process->successful() || $hasInternalError) {

                if ($taskName === "Setup Storage Link") {
                    $this->line('');
                    $this->line("<fg=yellow>[!] WARNING: Optimization step [{$taskName}] failed due to host engine environment constraints.</>");
                    $this->error("[x] Framework Error: The server blocked storage link generation (Likely 'exec' or 'symlink' functions are disabled via php.ini).");

                    $this->printFormattedOutput("{$taskName} Environment Exception Log", $hasInternalError ? $outputTrimmed : $errorLog);

                    if ($this->confirmYN(' Would you like ShipIt to execute a native Linux stream fallback override via SSH?', true)) {
                        $this->line('<fg=cyan>[*] Initializing Native OS Stream Override Pipeline...</>');

                        $fallbackCmdString = "cd '{$absolutePath}' && ln -sfn ../storage/app/public public/storage 2>&1";
                        $fallbackExec = "{$sshBase} " . escapeshellarg($fallbackCmdString);
                        $fallbackProcess = Process::timeout(self::PROCESS_TIMEOUT)->run($fallbackExec);

                        if ($fallbackProcess->successful()) {
                            $this->line('<fg=green>[+] SUCCESS: Native OS link override established successfully!</>');
                            $this->line("    -> Reference mapped: public/storage -> ../storage/app/public\n");
                            $state['steps'][] = $taskName . ' (native fallback)';
                            continue;
                        } else {
                            $this->error('[x] Error: Native OS override execution rejected by server security policy layers.');
                            $fallbackError = !empty($fallbackProcess->errorOutput()) ? $fallbackProcess->errorOutput() : $fallbackProcess->output();
                            $this->printFormattedOutput("Native Override Trace Log", $fallbackError);
                        }
                    }

                    $this->line('');
                    $this->line("<fg=yellow>============= MANUAL RESOLUTION ARCHITECTURE GUIDE =============</fg=yellow>");
                    $this->line("<fg=yellow>1. Enable 'exec' inside the 'disable_functions' directive within your host panel configuration rules.</fg=yellow>");
                    $this->line("<fg=yellow>2. Alternatively, fire this command string line directly within your manual terminal link context:</fg=yellow>");
                    $this->line("<fg=cyan>   ln -sfn ../storage/app/public public/storage</fg=cyan>");
                    $this->line("<fg=yellow>================================================================</fg=yellow>");
                    $this->line('');

                    $this->line("    -> Non-critical step bypassed. Continuing deployment pipeline safely...\n");
                    continue;
                }

                if (in_array($taskName, $nonCriticalTasks)) {
                    $this->line('');
                    $this->line("<fg=yellow>[!] WARNING: Optimization step [{$taskName}] encountered an operational error.</>");
                    $this->error("[x] Failure Details: The host environment rejected or failed this specific instruction.");

                    if (isset($manualFixSuggestions[$taskName])) {
                        $this->line("<fg=yellow>Please resolve this manually inside your Hostinger terminal:</fg=yellow>");
                        $this->line("<fg=cyan>   " . $manualFixSuggestions[$taskName] . "</fg=cyan>");
                    }
                    $this->line('');

                    $this->printFormattedOutput("{$taskName} Soft-Failure Trace Log", $errorLog);
                    $this->line("    -> Non-critical step bypassed. Continuing deployment pipeline safely...\n");
                    continue;
                }

                $this->line('');
                $this->error("[x] Fatal Circuit-Breaker: Critical optimization step failed at [{$taskName}]. Stopping deployment.");
                $this->printFormattedOutput("{$taskName} Fatal Error Trace Log", $errorLog);
                $state['failed_step'] = $taskName;

                break;

            } else {
                $this->line("    <fg=green>[+] Step [{$taskName}] completed successfully.</>");
                $state['steps'][] = $taskName;
                if ($taskName === 'Run Migrations') {
                    $state['migrations_ran'] = true;
                }

                if ($taskName === "Setup Storage Link" && !$this->option('debug') && !empty($outputTrimmed)) {
                    $this->line('    ' . $outputTrimmed);
                } elseif ($this->option('debug')) {
                    $this->line("    <comment>[DEBUG] Command Sent:</comment> {$commandString}");
                    $this->printFormattedOutput(
                        "{$taskName} Output Trace",
                        !empty($outputTrimmed) ? $outputTrimmed : "[Command completed with a silent/empty output buffer]"
                    );
                }
            }
        }

        // Maintenance mode ALWAYS comes back down — success or failure.
        if ($maintenanceDown) {
            $this->line('<fg=yellow>    ... Disabling maintenance mode ...</>');
            $upProcess = Process::timeout(120)->run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && php artisan up"));
            if ($upProcess->successful()) {
                $state['steps'][] = 'Maintenance mode disabled';
            } else {
                $state['maintenance_left_on'] = true;
                $this->error('[x] WARNING: Could not disable maintenance mode automatically. Run this on the server: php artisan up');
            }
        }

        $state['ok'] = $state['failed_step'] === null;
        return $state;
    }

    /**
     * Generate an APP_KEY on the server ONLY when the provisioned .env
     * does not already carry one. Regenerating unconditionally (the old
     * behaviour) silently invalidated every session and every encrypted
     * value whenever .env.production was re-uploaded.
     */
    private function generateAppKeyIfMissing(string $sshBase, string $absolutePath): void
    {
        $check = Process::run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && grep -q '^APP_KEY=base64:' .env && echo 'present' || echo 'missing'"));
        if (trim($check->output()) === 'present') {
            $this->line('    <fg=green>[+] Existing APP_KEY found in the uploaded .env — left untouched.</>');
            return;
        }
        Process::run("{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && php artisan key:generate --quiet"));
        $this->line('    <fg=green>[+] APP_KEY was empty — a fresh key has been generated.</>');
    }
}
