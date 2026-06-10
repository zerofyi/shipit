<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Commands\BasePushCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Exception;

class PushHostinger extends BasePushCommand
{
    protected $signature = 'push:hostinger
                            {--dry-run : Simulate deployment pipelines without editing server files}
                            {--debug   : Print comprehensive network and command execution outputs}';

    protected $description = 'Pushes updates to GitHub, builds local assets, and completes a manual deployment to Hostinger via optimized tar stream.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->info('🚀 Initializing Complete Hostinger Pipeline Execution...');
        $this->line('');

        try {
            // Phase 1: Parse and Validate environment parameter states
            $env = $this->validateLocalEnvironment(true);
            if (!$env) {
                return Command::FAILURE;
            }

            // Compute an SSH variant string layout ONLY for Hostinger background commands
            $sshRepoUrl = $env['repo_url'];
            if (str_starts_with($sshRepoUrl, 'http')) {
                if (preg_match('/https?:\/\/([^\/]+)\/([^\/]+)\/([^\/\s]+)/', $sshRepoUrl, $matches)) {
                    $sshRepoUrl = "git@{$matches[1]}:{$matches[2]}/{$matches[3]}";
                    if (!str_ends_with($sshRepoUrl, '.git')) {
                        $sshRepoUrl .= '.git';
                    }
                }
            }

            // Phase 2: Compile Frontend Assets Locally (Built exactly ONCE)
            if (!$this->compileFrontendAssetsLocally()) {
                return Command::FAILURE;
            }

            // Phase 3: Route Sub-Wizard task loops to manage Git configurations
            $this->info('🔄 Routing tasks into local Git setup wizard...');
            $gitWizardCode = $this->call('push:github', [
                '--dry-run' => $isDryRun,
                '--skip-assets' => true,
                '--debug' => $this->option('debug'),
            ]);

            if ($gitWizardCode !== 0) {
                $this->error('❌ Deployment aborted. Core structural codebase syncing failed.');
                return Command::FAILURE;
            }

            // Phase 4: Local-to-Server SSH Verification handshake
            $this->info('🔑 Initializing connection sequence with remote Hostinger node...');

            $userClean = trim($env['ssh_user']);
            $dirClean = trim($env['site_dir']);
            $absolutePath = "/home/{$userClean}/domains/{$dirClean}";

            $sshBase = sprintf(
                'ssh -p %d -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=15 %s@%s',
                $env['ssh_port'],
                $userClean,
                trim($env['ssh_host'])
            );

            // Execute an explicit directory check to guarantee deployment targets exist
            $pathCheckCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}' && echo 'exists' || echo 'missing'");
            $pathCheck = Process::run($pathCheckCmd);

            if (!$pathCheck->successful() || trim($pathCheck->output()) !== 'exists') {
                $this->line('');
                $this->error("❌ Error: Target deployment directory [{$absolutePath}] does not exist on your Hostinger server.");
                $this->line('💡 Please map and set up this domain directory correctly within your Hostinger control panel first.');
                return Command::FAILURE;
            }
            $this->info('✅ Production target directory path verified.');

            // Phase 5: Server-to-GitHub Trust Verification Engine
            $finalServerRepoUrl = $this->resolveServerToGitHubTrust($env['repo_url'], $sshRepoUrl, $sshBase, $isDryRun);
            if (!$finalServerRepoUrl) {
                return Command::FAILURE;
            }

            // Phase 6: Code Syncing & Production Pipeline Optimization Execution
            if (!$this->executeRemoteDeployment($finalServerRepoUrl, $sshBase, $absolutePath, $isDryRun)) {
                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $this->line('');
            $this->error('❌ Fatal unhandled exception terminated the deployment pipeline: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->line('');
        $this->info("🎉 Deployment successfully updated! Production live link: https://{$env['site_dir']}");
        return Command::SUCCESS;
    }

    private function resolveServerToGitHubTrust(string $httpRepoUrl, string $sshRepoUrl, string $sshBase, bool $isDryRun): ?string
    {
        $host = '';
        if (preg_match('/@([^:]+):/', $sshRepoUrl, $matches)) {
            $host = $matches[1];
        } elseif (preg_match('/https?:\/\/([^\/]+)/', $httpRepoUrl, $matches)) {
            $host = $matches[1];
        }
        $host = trim($host ?: 'github.com');

        if ($isDryRun) {
            $this->info('[DRY RUN] Skip trust layer evaluation.');
            return $httpRepoUrl;
        }

        // 1. Force feed hostname signatures safely into remote known_hosts mapping
        $this->info("🔍 Synchronizing host keys matching destination domain signature: {$host}");
        $scanCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && if ! grep -q '{$host}' ~/.ssh/known_hosts 2>/dev/null; then ssh-keyscan -H '{$host}' >> ~/.ssh/known_hosts 2>/dev/null; fi");
        Process::run($scanCmd);

        // 2. Intercept repository configuration profile context visibility variables
        $this->info('🔍 Resolving repository accessibility profile context...');

        $visibilityCheck = Process::env([
            'GITHUB_TOKEN'        => null,
            'GIT_ASKPASS'        => 'echo',
            'GIT_TERMINAL_PROMPT' => '0'
        ])->run('git -c credential.helper= ls-remote -h ' . escapeshellarg($httpRepoUrl));

        if ($visibilityCheck->successful()) {
            $this->info('✅ Public repository signature detected. Skipping authentication setup steps.');
            return $httpRepoUrl;
        }

        $this->warn('🔒 Private repository detected. Managing deployment keys on the server...');

        // 3. Check for existing server keys or generate an unpassphrased profile dynamically via RSA
        $keyCheckCmd = "{$sshBase} " . escapeshellarg("test -f ~/.ssh/id_rsa && echo 'exists' || echo 'missing'");
        $keyCheck = trim(Process::run($keyCheckCmd)->output());

        $keyPath = '~/.ssh/id_rsa';
        if ($keyCheck === 'missing') {
            $this->info('🔑 Key files absent on host server. Generating fresh unpassphrased RSA 4096-bit key pair...');

            $genCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && ssh-keygen -t rsa -b 4096 -P '' -f ~/.ssh/id_rsa");
            $genProcess = Process::run($genCmd);

            if (!$genProcess->successful()) {
                $this->error('❌ Failed to execute key generation command on Hostinger.');
                $this->printFormattedOutput('Keygen Error Output', $genProcess->errorOutput());
                return null;
            }
        }

        // Fetch public key footprint output string directly from the host filesystem
        $getPubCmd = "{$sshBase} " . escapeshellarg("cat {$keyPath}.pub");
        $publicKey = trim(Process::run($getPubCmd)->output());

        if (empty($publicKey)) {
            $this->error('❌ Failed to retrieve structural public key string from server configuration context.');
            return null;
        }

        // Normalize Public Key: Strip host trail comments to fulfill precise API payload checks
        $keyParts = explode(' ', $publicKey);
        $normalizedKey = (count($keyParts) >= 2) ? $keyParts[0] . ' ' . $keyParts[1] : $publicKey;

        // 4. Inject public key data string directly into GitHub Repository configurations if a token is readily present
        $token = env('GITHUB_API_TOKEN');
        if (!empty($token)) {
            $this->info('🤖 GITHUB_API_TOKEN found. Attempting automatic Deploy Key injection...');

            if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', trim($sshRepoUrl), $repoMatches)) {
                $owner = trim($repoMatches[1]);
                $repoName = trim($repoMatches[2]);

                $apiUrl = "https://api.github.com/repos/{$owner}/{$repoName}/keys";
                try {
                    // RESTORED: Check if key already exists on GitHub API first to prevent duplicate errors
                    $checkResponse = Http::withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim($token),
                    ])->get($apiUrl);

                    $alreadyLinked = false;
                    if ($checkResponse->successful()) {
                        foreach ($checkResponse->json() as $existingKey) {
                            $exParts = explode(' ', $existingKey['key']);
                            $normalizedExKey = (count($exParts) >= 2) ? $exParts[0] . ' ' . $exParts[1] : $existingKey['key'];
                            if ($normalizedExKey === $normalizedKey) {
                                $alreadyLinked = true;
                                break;
                            }
                        }
                    }

                    if ($alreadyLinked) {
                        $this->info('✅ Deploy key already recognized active on GitHub.');
                        return $sshRepoUrl;
                    }

                    $response = Http::timeout(15)->withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim($token),
                    ])->post($apiUrl, [
                        'title' => 'Hostinger Server Deployment Key',
                        'key' => $normalizedKey,
                        'read_only' => true
                    ]);

                    if ($response->successful() || $response->status() === 422) {
                        $this->info('✅ Remote security trust chain verified (Deploy key active).');
                        return $sshRepoUrl;
                    }
                } catch (Exception $e) {
                    $this->warn('⚠️  Automated token API registration handshake timed out.');
                }
                $this->warn('⚠️  Automated authentication registration failed. Reverting to manual fallback mode.');
            }
        }

        // Interactive manual key exchange console card layout
        $this->line('');
        $this->warn('📋 Action Required: Please append this server public key to your repository Deploy Keys:');
        $this->line("   👉 Navigate to: GitHub Repository → Settings → Deploy keys");
        $this->line('   👉 Click "Add deploy key", name it, paste the string below, and leave "Allow write access" UNCHECKED.');
        $this->line('');
        $this->line(str_repeat('-', 70));
        $this->info($publicKey);
        $this->line(str_repeat('-', 70));
        $this->line('');

        // RESTORED: Loop confirmation logic matching reference specification
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->confirmYN("Press ENTER after you have saved this deploy key to GitHub to continue execution (Attempt {$attempt}/{$maxAttempts})", true)) {
                $this->error('❌ Deployment cancelled by user option.');
                return null;
            }

            $this->info('🔄 Testing remote server authentication credentials against GitHub...');
            $testCmd = "{$sshBase} " . escapeshellarg("ssh -T -o StrictHostKeyChecking=accept-new git@{$host} 2>&1");
            $testProcess = Process::run($testCmd);
            $testOutput = strtolower($testProcess->output());

            if (str_contains($testOutput, 'successfully authenticated') || str_contains($testOutput, 'hi ')) {
                $this->info('✅ Key handshakes mapped successfully!');
                return $sshRepoUrl;
            }

            if ($attempt < $maxAttempts) {
                $this->warn('⚠️  GitHub rejected the connection. Double-check that the key is saved correctly.');
            }
        }

        $this->error('❌ Maximum key check attempts exhausted. Aborting deployment.');
        return null;
    }

    private function executeRemoteDeployment(string $repoUrl, string $sshBase, string $absolutePath, bool $isDryRun): bool
    {
        if ($isDryRun) {
            $this->info('[DRY RUN] Sync pipeline simulated successfully.');
            return true;
        }

        // 1. Synchronize application codebase layout via Git updates
        $this->info('🔄 Synchronizing codebase versions on server target...');
        $repoStatusCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}/.git' && echo 'pull' || echo 'clone'");
        $repoStatus = trim(Process::run($repoStatusCmd)->output());

        $branchCheck = Process::run('git branch --show-current');
        $branch = ($branchCheck->successful() && !empty(trim($branchCheck->output()))) ? trim($branchCheck->output()) : 'main';

        if ($repoStatus === 'clone') {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git clone -b {$branch} {$repoUrl} .");
        } else {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git remote set-url origin {$repoUrl} && git fetch origin && git checkout {$branch} && git pull origin {$branch}");
        }

        $syncProcess = Process::timeout(self::PROCESS_TIMEOUT)->run($syncCmd);
        if (!$syncProcess->successful()) {
            $this->error('❌ Codebase alignment step failed.');
            $this->printFormattedOutput('Sync Error Output', $syncProcess->errorOutput());
            return false;
        }
        $this->info('   ↳ Codebase successfully synced.');

        // 2. Synchronize frontend bundle directories via compressed Tarball streams over SSH
        if (is_dir(base_path('public/build'))) {
            $this->info('📤 Delivering compiled frontend bundles via compressed stream pipeline...');
            $remoteBuildPath = "{$absolutePath}/public/build";

            Process::run("{$sshBase} " . escapeshellarg("rm -rf '{$remoteBuildPath}' && mkdir -p '{$absolutePath}/public'"));

            $tarCmd = sprintf(
                'tar -czf - -C ./public build | %s "tar -xzf - -C %s"',
                $sshBase,
                escapeshellarg($absolutePath . '/public')
            );

            $this->debug("Running Asset Sync Command: {$tarCmd}");
            $tarProcess = Process::run($tarCmd);

            if (!$tarProcess->successful()) {
                $this->error('❌ Asset synchronization pipeline failed completely.');
                $this->printFormattedOutput('Asset Stream Failure Logs', $tarProcess->errorOutput());
                return false;
            }

            $this->info('   ↳ Frontend assets synchronized successfully.');
        }

        // 3. Complete remote Laravel deployment optimization framework (Strict Circuit-Breaker Loop)
        $this->info('⚙️  Running production optimization pipeline over SSH...');

        $remoteCommands = [
            "Ensure App Directory Context" => "cd '{$absolutePath}'",
            "Install Dependencies"          => "cd '{$absolutePath}' && composer install --no-dev --optimize-autoloader --no-interaction",
            "Setup Environment Config"      => "cd '{$absolutePath}' && if [ -f .env ]; then echo '👉 INFO: .env file already exists on Hostinger. Skipping creation safely.'; else if [ -f .env.example ]; then cp .env.example .env && php artisan key:generate --quiet && echo '✅ SUCCESS: Created fresh .env from .env.example'; else echo '⚠️ WARNING: .env.example is missing! Could not auto-generate .env'; fi; fi",
            "Run Migrations"               => "cd '{$absolutePath}' && php artisan migrate --force",
            
            // Intelligent real-time decision-making with output capturing
            "Setup Storage Link"           => "cd '{$absolutePath}' && " .
                                            "if [ -L public/storage ]; then " .
                                            "    echo '👉 INFO: A symbolic link already exists at public/storage. Skipping creation.'; " .
                                            "elif [ -d public/storage ]; then " .
                                            "    echo '⚠️ WARNING: A physical directory already exists at public/storage! Laravel needs this path to be clear to map the link.'; " .
                                            "else " .
                                            "    echo '⚡ ACTION: No existing link found. Running fresh artisan storage:link command now...'; " .
                                            "    php artisan storage:link 2>&1; " .
                                            "fi",

            "Setup Public HTML Symlink"    => "cd '{$absolutePath}' && rm -rf public_html && ln -sfn public public_html",
            "Clear Optimization Cache"     => "cd '{$absolutePath}' && php artisan optimize:clear",
            "Warm Production Cache"        => "cd '{$absolutePath}' && php artisan optimize"
        ];

        $nonCriticalTasks = [
            "Setup Environment Config",
            "Setup Storage Link",
            "Setup Public HTML Symlink",
            "Clear Optimization Cache",
            "Warm Production Cache"
        ];

        $manualFixSuggestions = [
            "Setup Environment Config"   => "Manually create your `.env` file in the repository root and run: php artisan key:generate",
            "Setup Storage Link"         => "Create the symlink manually using raw Linux streams: ln -sfn ../storage/app/public public/storage",
            "Setup Public HTML Symlink"  => "Link your public folder to the web root folder manually: ln -sfn public public_html",
            "Clear Optimization Cache"   => "Clear your application cache manually directly on the host: php artisan cache:clear",
            "Warm Production Cache"      => "Optimize your framework configurations manually: php artisan optimize"
        ];

        foreach ($remoteCommands as $taskName => $commandString) {
            $this->debug("Executing task: {$taskName}");
            $execCmd = "{$sshBase} " . escapeshellarg($commandString);
            $process = Process::timeout(self::PROCESS_TIMEOUT)->run($execCmd);

            $errorLog = !empty($process->errorOutput()) ? $process->errorOutput() : $process->output();
            $outputTrimmed = trim($process->output());

            // Flag to track if we encountered a hidden internal script error inside an exit code 0
            $hasInternalError = (in_array($taskName, $nonCriticalTasks) && str_contains($outputTrimmed, 'Call to undefined function'));

            // 🔴 CONDITION: Command shell reported failure OR returned a masked framework crash output
            if (!$process->successful() || $hasInternalError) {
                
                // Handle the Storage Link specific recovery wizard flow
                if ($taskName === "Setup Storage Link") {
                    $this->line('');
                    $this->warn("⚠️  WARNING: Optimization step [{$taskName}] failed due to host engine environment constraints.");
                    $this->error("❌ Framework Error: The server blocked storage link generation (Likely 'exec' or 'symlink' functions are disabled via php.ini).");
                    
                    $this->printFormattedOutput("{$taskName} Environment Exception Log", $hasInternalError ? $outputTrimmed : $errorLog);
                    
                    // 🤖 INTERACTIVE WIZARD: Request permissions to run native OS fallback streaming overrides
                    if ($this->confirm("👉 Framework automation failed. Would you like ShipIt to execute a native Linux stream fallback override via SSH?", true)) {
                        $this->info("⚡ Initializing Native OS Stream Override Pipeline...");
                        
                        $fallbackCmdString = "cd '{$absolutePath}' && ln -sfn ../storage/app/public public/storage 2>&1";
                        $fallbackExec = "{$sshBase} " . escapeshellarg($fallbackCmdString);
                        $fallbackProcess = Process::timeout(self::PROCESS_TIMEOUT)->run($fallbackExec);
                        
                        if ($fallbackProcess->successful()) {
                            $this->info("✅ SUCCESS: Native OS link override established successfully!");
                            $this->line("<fg=green>   ↳ Reference mapped: public/storage -> ../storage/app/public</fg=green>\n");
                            continue; // Recovery script worked, advance securely to next step
                        } else {
                            $this->error("❌ Error: Native OS override execution rejected by server security policy layers.");
                            $fallbackError = !empty($fallbackProcess->errorOutput()) ? $fallbackProcess->errorOutput() : $fallbackProcess->output();
                            $this->printFormattedOutput("Native Override Trace Log", $fallbackError);
                        }
                    }
                    
                    // 📘 RECOVERY GUIDE: Render clean fallback directions if interactive validation is skipped/failed
                    $this->line('');
                    $this->line("<fg=yellow>============= MANUAL RESOLUTION ARCHITECTURE GUIDE =============</fg=yellow>");
                    $this->line("<fg=yellow>1. Resolve this via hPanel or your server manager by enabling 'exec' inside the 'disable_functions' directive and re-running ShipIt.</fg=yellow>");
                    $this->line("<fg=yellow>2. Alternatively, log into your manual SSH terminal prompt and fire this raw terminal line directly:</fg=yellow>");
                    $this->line("<fg=cyan>   ln -sfn ../storage/app/public public/storage</fg=cyan>");
                    $this->line("<fg=yellow>================================================================</fg=yellow>");
                    $this->line('');
                    
                    $this->line("<info>skip ⏭️  Non-critical step bypassed. Continuing deployment pipeline safely...</info>\n");
                    continue;
                }

                // Handle generic Soft-Failures for other Non-Critical Tasks
                if (in_array($taskName, $nonCriticalTasks)) {
                    $this->line('');
                    $this->warn("⚠️  WARNING: Optimization step [{$taskName}] encountered an operational error.");
                    $this->error("❌ Failure Details: The host environment rejected or failed this specific instruction.");
                    
                    if (isset($manualFixSuggestions[$taskName])) {
                        $this->line("<fg=yellow>👉 Please resolve this manually inside your Hostinger terminal:</fg=yellow>");
                        $this->line("<fg=cyan>   " . $manualFixSuggestions[$taskName] . "</fg=cyan>");
                    }
                    $this->line('');
                    
                    $this->printFormattedOutput("{$taskName} Soft-Failure Trace Log", $errorLog);
                    $this->line("<info>skip ⏭️  Non-critical step bypassed. Continuing deployment pipeline safely...</info>\n");
                    continue; 
                }

                // Handle Strict Hard-Failures for Critical Steps
                $this->line('');
                $this->error("❌ Fatal Circuit-Breaker: Critical optimization step failed at [{$taskName}]. Stopping deployment.");
                $this->printFormattedOutput("{$taskName} Fatal Error Trace Log", $errorLog);
                return false;
                
            } else {
                // 🟢 PATH: Command executed smoothly with clear exit codes
                $this->info("   ↳ Step [{$taskName}] completed successfully.");

                if ($taskName === "Setup Storage Link" && !$this->option('debug') && !empty($outputTrimmed)) {
                    $this->line($outputTrimmed);
                } elseif ($this->option('debug')) {
                    $this->line("<comment>[DEBUG] Command Sent:</comment> {$commandString}");
                    $this->printFormattedOutput(
                        "{$taskName} Output Trace",
                        !empty($outputTrimmed) ? $outputTrimmed : "[Command completed with a silent/empty output buffer]"
                    );
                }
            }
        }

        return true;
    }
}
