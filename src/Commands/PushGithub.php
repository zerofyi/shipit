<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Commands\BasePushCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Exception;

class PushGithub extends BasePushCommand
{
    protected $signature = 'push:github
                            {--dry-run     : Simulate all steps without changing your repository}
                            {--debug       : Display raw underlying git execution logs}
                            {--skip-assets : Skip compiling frontend assets locally}
                            {--timeout=    : Git push timeout window in seconds (default: 60)}';

    protected $description = 'Compiles production frontend assets locally and executes the Git push wizard.';

    private const INITIAL_COMMIT_MESSAGE = 'Version 1.0.0 - Initial version';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $timeout = (int) ($this->option('timeout') ?: 60);
        $skipAssets = (bool) $this->option('skip-assets');

        $this->line('');
        $this->info('🚀 Executing Local Git Deployment Wizard...');
        if ($isDryRun) {
            $this->warn('⚠️  Mode: DRY RUN — no commits or push sequences will be submitted.');
        }
        $this->line('');

        try {
            // Step 1 — Validate environment configurations
            $env = $this->validateLocalEnvironment(false);
            if (!$env) {
                return Command::FAILURE;
            }

            // Step 2 — Compile Frontend Assets Locally
            if (!$skipAssets) {
                if (!$this->compileFrontendAssetsLocally()) {
                    return Command::FAILURE;
                }
            } else {
                $this->debug('Frontend asset compilation handled by parent command. Skipping duplicate build.');
            }

            // Step 3 — Git Tracking and Synchronization Pipeline
            if (!$this->executeGitSetupWizard($env['repo_url'], $timeout, $isDryRun)) {
                return Command::FAILURE;
            }
        } catch (Exception $e) {
            $this->error('❌ System exception encountered inside Git engine loop: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info('🎉 Local GitHub wizard sequence finished successfully!');
        return Command::SUCCESS;
    }

    private function executeGitSetupWizard(string $repoUrl, int $timeout, bool $isDryRun): bool
    {
        // 1. Verify system Git binary accessibility
        $gitCheckCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where git' : 'which git';
        if (!Process::run($gitCheckCmd)->successful()) {
            $this->error('❌ Git binary is not installed or not found in your system PATH.');
            return false;
        }

        // 2. Resolve Active Repository Working Tree status
        $isInit = Process::run('git rev-parse --is-inside-work-tree');
        if (!$isInit->successful() || trim($isInit->output()) !== 'true') {
            $this->warn('[WARN] Current working directory is not an active Git repository.');
            if (!$this->confirmYN('Initialize a new Git repository here?', true)) {
                $this->error('❌ Aborted. A local Git repository setup is required.');
                return false;
            }

            if (!$isDryRun) {
                Process::run('git init');
                Process::run('git add .');
                $commit = Process::run('git commit -m ' . escapeshellarg(self::INITIAL_COMMIT_MESSAGE));
                if (!$commit->successful()) {
                    $this->error('❌ Initial repository commit failed.');
                    return false;
                }
            }
            $this->info('✅ New Git repository established with initial commit structure.');
        }

        // 3. Process Pending Working Tree Changes
        $status = Process::run('git status --porcelain');
        if (!empty(trim($status->output()))) {
            $this->warn('⚠️  Uncommitted modifications detected in your working directory:');
            $this->printFormattedOutput('Changed Files', $status->output());

            $msg = $this->ask('Enter a commit message for these changes', 'Incremental updates');
            if (empty($msg) || trim($msg) === '') {
                $this->error('❌ Deployment stopped. A clean commit description is mandatory.');
                return false;
            }

            if (!$isDryRun) {
                Process::run('git add .');
                $commit = Process::run('git commit -m ' . escapeshellarg(trim($msg)));
                if (!$commit->successful()) {
                    $this->error('❌ Staged changes commit process failed.');
                    return false;
                }
            }
            $this->info('✅ Current workspace files safely staged and committed.');
        } else {
            $this->info('✅ Workspace status clean. No tracking adjustments needed.');
        }

        // 4. Remote Origin Reference Integrity Validation
        $currentRemote = Process::run('git config --get remote.origin.url');
        if (!$currentRemote->successful()) {
            $this->info("ℹ️ Registering missing remote configuration matching tracking address: {$repoUrl}");
            if (!$isDryRun) {
                $addRemote = Process::run('git remote add origin ' . escapeshellarg($repoUrl));
                if (!$addRemote->successful()) {
                    $this->error('❌ Registration of tracking remote source failed.');
                    return false;
                }
            }
        }

        // 5. Detect and Extract Active Branch Name safely
        $branchCheck = Process::run('git branch --show-current');
        $branch = ($branchCheck->successful() && !empty(trim($branchCheck->output()))) ? trim($branchCheck->output()) : 'main';

        // 6. Push Submission & Fail-Fast Diagnostics
        $this->line('');
        $this->info("Pushing branch [{$branch}] to target remote destination...");
        $this->line('');

        if ($isDryRun) {
            $this->info('[DRY RUN] Push tracking skip sequence completed.');
            return true;
        }

        // Keeps your local HTTPS workflow completely uninhibited. Bypasses local SSH issues natively.
        $push = Process::timeout($timeout)->run('git push -u origin ' . escapeshellarg($branch));

        if ($this->option('debug')) {
            $this->printFormattedOutput('Raw Out', $push->output());
        }

        if ($push->successful()) {
            $this->info('✅ Sync successful. Repository contents updated on GitHub.');
            return true;
        }

        // Structural Error Capture & Diagnostic Analysis
        $this->error('❌ Push target synchronization encountered a fatal exception.');
        $err = $push->errorOutput();
        $errLower = strtolower($err);

        if (str_contains($errLower, 'authentication') || str_contains($errLower, 'password') || str_contains($errLower, '403')) {
            $this->warn('🔍 Cause: Authentication denied. Validate local Git credentials or map out a GitHub Personal Access Token.');
        } elseif (str_contains($errLower, 'not found') || str_contains($errLower, '404')) {
            $this->warn('🔍 Cause: Target URL invalid. Confirm the online repository exists and maps precisely to GITHUB_REPO_URL.');
        } elseif (str_contains($errLower, 'rejected') || str_contains($errLower, 'non-fast-forward')) {
            $this->warn("🔍 Cause: Remote branch contains changes missing locally. Run: git pull --rebase origin {$branch}");
        }

        $this->printFormattedOutput('Git Standard Errors', $err);
        return false;
    }
}
