<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Commands\BasePushCommand;
use Zerofyi\ShipIt\Support\GitChanges;
use Zerofyi\ShipIt\Support\Urls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Exception;

class PushGithub extends BasePushCommand
{
    protected $signature = 'push:github
                            {--dry-run     : Simulate all steps without changing your repository}
                            {--debug       : Display raw underlying git execution logs}
                            {--skip-assets : Skip compiling frontend assets locally}
                            {--timeout=    : Git push timeout window in seconds (default: 60)}
                            {--site=       : Site profile from config/shipit.php (default: .env values)}
                            {--message=    : Commit message (skips the interactive prompt)}
                            {--only        : Selective deploy — interactively choose which changed files to commit and push}
                            {--files=      : Selective deploy — comma-separated list of changed files to commit and push}';

    protected $description = 'Compiles production frontend assets locally and executes the Git push wizard (supports selective file deploys with --only / --files).';

    private const INITIAL_COMMIT_MESSAGE = 'Version 0.0.1 - Initial version';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $timeout = (int) ($this->option('timeout') ?: 60);
        $skipAssets = (bool) $this->option('skip-assets');

        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] Executing Local Git Deployment Wizard...</>');
        if ($isDryRun) {
            $this->line('<fg=yellow>[!] Mode: DRY RUN — nothing will be committed or pushed.</>');
        }
        $this->line('');

        try {
            // Step 1 — Validate environment configurations
            $env = $this->resolveSiteOrFail($this->option('site'), 'github');
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
            $this->error('[x] System exception encountered inside Git engine loop: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->line('<fg=green;options=bold>[==] Local GitHub wizard sequence finished successfully!</>');
        return Command::SUCCESS;
    }

    private function executeGitSetupWizard(string $repoUrl, int $timeout, bool $isDryRun): bool
    {
        // 1. Verify system Git binary accessibility
        $gitCheckCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where git' : 'which git';
        if (!Process::run($gitCheckCmd)->successful()) {
            $this->error('[x] Git binary is not installed or not found in your system PATH.');
            return false;
        }

        // 2. Resolve Active Repository Working Tree status
        $isInit = Process::run('git rev-parse --is-inside-work-tree');
        if (!$isInit->successful() || trim($isInit->output()) !== 'true') {
            $this->line('<fg=yellow>[!] Current working directory is not an active Git repository.</>');

            if (!$this->confirmYN('Initialize a new Git repository here?', true)) {
                $this->error('[x] Aborted. A local Git repository setup is required.');
                return false;
            }

            if ($isDryRun) {
                $this->line('<fg=green>[+] [DRY RUN] Would initialize a new Git repository and create the initial commit.</>');
            } else {
                Process::run('git init');
                Process::run('git add .');
                $commit = Process::run('git commit -m ' . escapeshellarg(self::INITIAL_COMMIT_MESSAGE));
                if (!$commit->successful()) {
                    $this->error('[x] Initial repository commit failed.');
                    return false;
                }
                $this->line('<fg=green>[+] New Git repository established with initial commit structure.</>');
            }
        }

        // 3. Process Pending Working Tree Changes (full or selective)
        $status = Process::run('git status --porcelain');
        // Deliberately NOT trim()ed: the first porcelain line can start
        // with a space (" M path") and GitChanges parses fixed columns.
        $statusOutput = $status->output();

        if (trim($statusOutput) !== '') {
            if (!$this->commitChanges($statusOutput, $isDryRun)) {
                return false;
            }
        } else {
            $this->line('<fg=green>[+] Workspace status clean. No tracking adjustments needed.</>');
        }

        // 4. Remote Origin Reference Integrity Validation
        if (!$this->ensureOriginMatches($repoUrl, $isDryRun)) {
            return false;
        }

        // 5. Detect and Extract Active Branch Name safely
        $branchCheck = Process::run('git branch --show-current');
        $branch = ($branchCheck->successful() && !empty(trim($branchCheck->output()))) ? trim($branchCheck->output()) : 'main';

        // 6. Push Submission & Fail-Fast Diagnostics
        $this->line('');
        $this->line("<fg=yellow>[o] Pushing branch [{$branch}] to remote destination... [Processing]</>");
        $this->line('');

        if ($isDryRun) {
            $this->line("<fg=green>[+] [DRY RUN] Would push branch [{$branch}] to origin now. Nothing was pushed.</>");
            return true;
        }

        $push = Process::timeout($timeout)->run('git push -u origin ' . escapeshellarg($branch));

        if ($this->option('debug')) {
            $this->printFormattedOutput('Raw Out', $push->output());
        }

        if ($push->successful()) {
            $this->line('<fg=green>[+] Sync successful. Repository contents updated on GitHub.</>');
            return true;
        }

        // Structural Error Capture & Diagnostic Analysis
        $this->error('[x] Push target synchronization encountered a fatal exception.');
        $err = $push->errorOutput();
        $errLower = strtolower($err);

        if ($this->isAuthFailure($errLower)) {
            $this->line('<fg=yellow>[!] Cause: Authentication denied. Validate local Git credentials or map out a GitHub Personal Access Token.</>');
            // Local credentials first, token only as a fallback: the push
            // is retried once with a pasted token, then the origin remote
            // is restored so the token never stays in .git/config.
            if (!$isDryRun && $this->isInteractiveRun()
                && $this->offerTokenRetry($repoUrl, $branch, $timeout)) {
                return true;
            }
        } elseif (str_contains($errLower, 'not found') || str_contains($errLower, '404')) {
            $this->line('<fg=yellow>[!] Cause: Target URL invalid. Confirm the online repository exists and maps precisely to GITHUB_REPO_URL.</>');
        } elseif (str_contains($errLower, 'rejected') || str_contains($errLower, 'non-fast-forward')) {
            $this->line("<fg=yellow>[!] Cause: Remote branch contains changes missing locally. Run: git pull --rebase origin {$branch}</>");
        }

        $this->printFormattedOutput('Git Standard Errors', $err);
        return false;
    }

    /**
     * Is this push failure an authentication failure? Covers HTTPS
     * ("Authentication failed", bad password, 401/403) and SSH
     * ("Permission denied (publickey)") — both are cases where a
     * token-based HTTPS retry can save the push.
     */
    private function isAuthFailure(string $errLower): bool
    {
        return str_contains($errLower, 'authentication')
            || str_contains($errLower, 'password')
            || str_contains($errLower, 'permission denied')
            || str_contains($errLower, '401')
            || str_contains($errLower, '403');
    }

    /**
     * Offer a one-shot token retry after local git credentials were
     * denied. The token is asked for (hidden input), used for a single
     * push, and never saved anywhere: the origin remote is restored to
     * the plain URL immediately afterwards, even when the retry fails.
     * Returns true only when the retry push succeeded.
     */
    private function offerTokenRetry(string $repoUrl, string $branch, int $timeout): bool
    {
        if (!$this->confirmYN(' Local Git credentials were denied. Try pushing with a GitHub Personal Access Token instead?', false)) {
            return false;
        }

        $token = $this->secret(' Paste a GitHub Personal Access Token (needs Contents: Read & Write to push)');
        if ($token === null || trim((string) $token) === '') {
            $this->error('[x] No token given. Push cancelled.');
            return false;
        }
        $token = trim((string) $token);

        $tokenUrl = Urls::withToken($repoUrl, $token);
        if ($tokenUrl === null) {
            $this->error('[x] Could not build a token URL from GITHUB_REPO_URL. Push cancelled.');
            return false;
        }

        $this->line('<fg=cyan>[*] Retrying the push with the token — used once, never saved...</>');

        Process::run('git remote set-url origin ' . escapeshellarg($tokenUrl));
        $retry = Process::timeout($timeout)->run('git push -u origin ' . escapeshellarg($branch));
        $restored = Process::run('git remote set-url origin ' . escapeshellarg($repoUrl));

        if (!$restored->successful()) {
            $this->error('[x] WARNING: could not restore the origin remote — the token may still be in .git/config.');
            $this->line("    Fix it with: git remote set-url origin {$repoUrl}");
        }

        if ($retry->successful()) {
            $this->line('<fg=green>[+] Sync successful. Repository contents updated on GitHub.</>');
            $this->line('<fg=cyan>[*] Origin remote restored to the token-free URL.</>');
            return true;
        }

        $this->error('[x] Push with the token failed too.');
        $this->printFormattedOutput(
            'Git Standard Errors',
            str_replace($token, '***', $retry->errorOutput())
        );

        return false;
    }

    /**
     * Stage + commit working-tree changes: either everything (classic
     * behaviour) or only the files chosen via --only / --files.
     * Remaining changes are never staged, committed or touched.
     */
    private function commitChanges(string $statusOutput, bool $isDryRun): bool
    {
        $entries = GitChanges::parsePorcelain($statusOutput);
        $selective = $this->option('only') || !empty($this->option('files'));

        $this->line('<fg=yellow>[!] Uncommitted modifications detected in your working directory:</>');
        $this->printFormattedOutput('Changed Files', $statusOutput);

        $selectedEntries = $entries;

        if ($selective) {
            $unselectable = array_filter($entries, fn ($e) => !GitChanges::isSelectable($e));
            if ($unselectable !== []) {
                $this->error('[x] Some changed files are in a conflicted state and cannot be deployed selectively. Resolve merge conflicts first.');
                return false;
            }

            $wanted = $this->parseFilesOption($this->option('files'));
            if ($wanted !== []) {
                $matched = $this->matchFilesOption($entries, $wanted);
                if ($matched === null) {
                    return false;
                }
                $selectedEntries = $matched;
            } else {
                $picked = $this->interactiveSelectFiles($entries);
                if ($picked === null) {
                    $this->error('[x] Selective deploy cancelled. Nothing was committed.');
                    return false;
                }
                $selectedEntries = $picked;
            }

            $selectedPaths = array_map(fn ($e) => $e['path'], $selectedEntries);
            $leftBehind = array_values(array_diff(array_map(fn ($e) => $e['path'], $entries), $selectedPaths));

            if (!$isDryRun && !$this->confirmPartialDeploy($selectedPaths, $leftBehind)) {
                $this->error('[x] Selective deploy cancelled. Nothing was committed.');
                return false;
            }
        }

        // Commit message: --message option wins; otherwise prompt as before.
        $msg = $this->option('message');
        if ($msg === null || trim((string) $msg) === '') {
            $msg = $this->ask('Enter a commit message for these changes', 'chore(deploy): automated sync via ShipIt');
        }
        if (empty($msg) || trim((string) $msg) === '') {
            $this->error('[x] Deployment stopped. A clean commit description is mandatory.');
            return false;
        }

        if ($isDryRun) {
            $count = count($selectedEntries);
            $scope = $selective ? "{$count} selected file(s)" : 'all changes';
            $this->line("<fg=green>[+] [DRY RUN] Would stage and commit {$scope} with message: \"" . trim((string) $msg) . '". Nothing was committed.</>');
            return true;
        }

        if ($selective) {
            $addCmd = 'git add --';
            foreach ($selectedEntries as $entry) {
                // Only the (new) path is staged. Renames detected by git
                // are already staged by definition — re-adding the old
                // path makes git fail with "pathspec did not match".
                // Unstaged moves appear as separate delete + untracked
                // entries, and those delete paths stage fine on their own.
                $addCmd .= ' ' . escapeshellarg($entry['path']);
            }
            $stage = Process::run($addCmd);
        } else {
            $stage = Process::run('git add .');
        }

        if (!$stage->successful()) {
            $this->error('[x] Failed to stage changes.');
            $this->printFormattedOutput('Git Stage Errors', $stage->errorOutput());
            return false;
        }

        $commit = Process::run('git commit -m ' . escapeshellarg(trim((string) $msg)));
        if (!$commit->successful()) {
            $this->error('[x] Staged changes commit process failed.');
            $this->printFormattedOutput('Git Commit Errors', $commit->errorOutput());
            return false;
        }

        $this->line($selective
            ? '<fg=green>[+] Selected files safely staged and committed. Your other changes were left untouched.</>'
            : '<fg=green>[+] Current workspace files safely staged and committed.</>');
        return true;
    }

    /**
     * Guarantee the local "origin" remote is the repository ShipIt is
     * configured for. Previously a mismatched origin was silently used,
     * which could push code to the wrong repository.
     */
    private function ensureOriginMatches(string $repoUrl, bool $isDryRun): bool
    {
        $currentRemote = Process::run('git config --get remote.origin.url');

        if (!$currentRemote->successful() || trim($currentRemote->output()) === '') {
            $this->line("<fg=cyan>[*] Registering missing remote configuration matching tracking address: {$repoUrl}</>");
            if ($isDryRun) {
                $this->line('<fg=green>[+] [DRY RUN] Would register the origin remote now.</>');
                return true;
            }
            $addRemote = Process::run('git remote add origin ' . escapeshellarg($repoUrl));
            if (!$addRemote->successful()) {
                $this->error('[x] Registration of tracking remote source failed.');
                return false;
            }
            return true;
        }

        $existing = trim($currentRemote->output());
        if (Urls::sameRepository($existing, $repoUrl)) {
            return true;
        }

        $this->line('');
        $this->line('<fg=yellow>[!] WARNING: your git "origin" remote points somewhere else than GITHUB_REPO_URL:</>');
        $this->line("    origin          : {$existing}");
        $this->line("    GITHUB_REPO_URL : {$repoUrl}");
        $this->line('    Pushing now would send your code to the origin repository above.');
        $this->line('');

        if ($isDryRun) {
            $this->line('<fg=yellow>[!] [DRY RUN] Origin mismatch detected. A real run would ask you to resolve it before pushing.</>');
            return true;
        }

        if (!$this->confirmYN(' Update origin to match GITHUB_REPO_URL and continue?', false)) {
            $this->error('[x] Aborted. Fix the remote (git remote set-url origin <url>) or update GITHUB_REPO_URL, then run again.');
            return false;
        }

        $setUrl = Process::run('git remote set-url origin ' . escapeshellarg($repoUrl));
        if (!$setUrl->successful()) {
            $this->error('[x] Failed to update the origin remote.');
            return false;
        }

        $this->line('<fg=green>[+] Origin remote updated to match GITHUB_REPO_URL.</>');
        return true;
    }
}
