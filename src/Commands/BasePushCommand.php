<?php

namespace Zerofyi\ShipIt\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Zerofyi\ShipIt\Support\GitChanges;
use Zerofyi\ShipIt\Support\SiteConfig;
use Exception;

abstract class BasePushCommand extends Command
{
    /**
     * Shared Process Runtime Timeout (5 Minutes max for npm/composer updates)
     */
    protected const PROCESS_TIMEOUT = 300;

    /**
     * The resolved configuration for the target site (see SiteConfig).
     *
     * @var array{site:string, repo_url:?string, github_token:?string,
     *            ssh_host:?string, ssh_user:?string, ssh_port:int, site_dir:?string}
     */
    protected array $env = [];

    /**
     * Resolve + validate the site configuration, printing a clean,
     * actionable report when something is wrong. Returns the config on
     * success and null when the command must abort.
     *
     * Site profiles (config/shipit.php "sites") let one Hostinger account
     * host many websites; every value falls back to the classic .env
     * variables, so existing single-site setups work unchanged.
     *
     * @param 'github'|'server'|'full' $scope
     *   github — repository URL + API token only (push:github)
     *   server — SSH fields only (backup / rollback / doctor / env)
     *   full   — everything (push:hostinger)
     */
    protected function resolveSiteOrFail(?string $profile, string $scope): ?array
    {
        if (!SiteConfig::profileExists($profile)) {
            $this->error(" [x] Unknown site profile: '{$profile}'.");
            $known = SiteConfig::profileNames();
            $this->line($known === []
                ? '     No profiles are defined in config/shipit.php yet ("sites" array).'
                : '     Defined profiles: ' . implode(', ', $known));
            return null;
        }

        $cfg = SiteConfig::resolve($profile);

        $errors = match ($scope) {
            'github' => SiteConfig::validateGithub($cfg),
            'server' => SiteConfig::validateServer($cfg, needGithub: false),
            default => SiteConfig::validateServer($cfg, needGithub: true),
        };

        if ($errors !== []) {
            $this->line('');
            $this->error(' ══════════════════════════════════════════════════════════════');
            $this->error('  CONFIGURATION ERROR: Environment Validation Failed!');
            $this->error(' ══════════════════════════════════════════════════════════════');
            $this->line('  The following required variables are missing or invalid:');
            $this->line('');
            foreach ($errors as $error) {
                $this->line("   [x] {$error}");
            }
            $this->line('');
            $this->line('  REQUIRED ACTION: check your .env file (and the config/shipit.php');
            $this->line('  site profile, when one is selected), then run the command again.');
            $this->error(' ══════════════════════════════════════════════════════════════');
            $this->line('');
            return null;
        }

        $this->env = $cfg;
        return $cfg;
    }

    /**
     * Check, install, and cleanly compile local frontend assets exactly once.
     */
    protected function compileFrontendAssetsLocally(): bool
    {
        if (!file_exists(base_path('package.json'))) {
            $this->debug('No package.json detected. Skipping frontend asset pipeline.');
            return true;
        }

        $this->line('<fg=cyan>[*] package.json detected. Verifying local Node environment...</>');

        // Cross-platform check for npm binary presence
        $npmCheckCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where npm' : 'which npm';
        try {
            $npmCheck = Process::run($npmCheckCmd);
            if (!$npmCheck->successful()) {
                $this->line('<fg=yellow>[!] npm binary not found on your local computer. Skipping asset compilation.</>');
                $this->line('<fg=yellow>    WARNING: if this app has frontend sources, production may keep stale assets.</>');
                return true;
            }
        } catch (Exception $e) {
            $this->line('<fg=yellow>[!] Failed to look up local npm environment path. Proceeding cautiously...</>');
            return true;
        }

        try {
            // Auto-install missing local dependencies safely
            if (!is_dir(base_path('node_modules'))) {
                $this->line('<fg=yellow>[o] Local node_modules missing. Running npm install... [Processing]</>');
                $install = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm install');

                if (!$install->successful()) {
                    $this->error('[x] Local "npm install" failed.');
                    $this->line($install->errorOutput());
                    return false;
                }
            }

            // Run localized optimization production compilation build
            $this->line('<fg=yellow>[o] Compiling frontend assets via npm run build... [Processing]</>');
            $build = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm run build');

            if (!$build->successful()) {
                $this->error('[x] Local frontend compilation failed.');
                $this->line($build->errorOutput());
                return false;
            }
        } catch (Exception $e) {
            $this->error('[x] Fatal error encountered during local frontend build pipeline: ' . $e->getMessage());
            return false;
        }

        $this->line('<fg=green>[+] Frontend assets built successfully.</>');
        return true;
    }

    /**
     * Enforces typed validation for interactive prompts to prevent script runaways.
     * A blank answer returns the caller's $default — destructive actions in
     * ShipIt always pass $default = false (or demand typed confirmation),
     * so an accidental Enter can never authorize them.
     */
    protected function confirmYN(string $question, bool $default = false): bool
    {
        $hint = $default ? '(Y/n)' : '(y/N)';
        $answer = $this->ask("{$question} {$hint}");

        if ($answer === null || trim((string) $answer) === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $answer)), ['y', 'yes'], true);
    }

    /**
     * Print diagnostic console information if the debug flag is active.
     */
    protected function debug(string $message): void
    {
        if ($this->hasOption('debug') && $this->option('debug')) {
            $this->line("<fg=gray>[DEBUG] {$message}</>");
        }
    }

    /**
     * Neatly display and indent external shell process output channels.
     */
    protected function printFormattedOutput(string $title, string $output): void
    {
        if (empty(trim($output))) {
            return;
        }

        $this->line('');
        $this->line("  <options=bold>{$title}:</>");
        foreach (explode("\n", trim($output)) as $line) {
            $this->line('   ' . rtrim($line));
        }
        $this->line('');
    }

    /**
     * Present the changed files as a numbered list and let the user pick a
     * subset for a selective deploy. Returns selected entries, or null when
     * the user aborts.
     *
     * @param array<int, array{path:string, orig_path:?string, code:string, label:string}> $entries
     * @return array<int, array{path:string, orig_path:?string, code:string, label:string}>|null
     */
    protected function interactiveSelectFiles(array $entries): ?array
    {
        $this->line('');
        $this->info(' Changed files in your working tree:');
        foreach ($entries as $i => $entry) {
            $num = $i + 1;
            $rename = $entry['orig_path'] ? " (from {$entry['orig_path']})" : '';
            $this->line("   [{$num}] {$entry['label']}: {$entry['path']}{$rename}");
        }
        $this->line('');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $answer = $this->ask(' Enter the numbers to deploy (e.g. 1,3,5 or 2-4, or "all") — leave empty to cancel');
            if ($answer === null || trim((string) $answer) === '') {
                return null;
            }
            $parsed = GitChanges::parseSelection((string) $answer, count($entries));
            if ($parsed['error'] !== null) {
                $this->warn(' ' . $parsed['error']);
                continue;
            }
            return array_map(fn ($idx) => $entries[$idx], $parsed['indices']);
        }

        return null;
    }

    /**
     * Show exactly what a selective deploy will ship (and what stays
     * behind) and confirm. Returns false when the user backs out.
     *
     * @param string[] $selectedPaths
     * @param string[] $leftBehindPaths
     */
    protected function confirmPartialDeploy(array $selectedPaths, array $leftBehindPaths): bool
    {
        $this->line('');
        $this->info(' Selective deploy — only these files will be committed and pushed:');
        foreach ($selectedPaths as $path) {
            $this->line("   + {$path}");
        }
        if ($leftBehindPaths !== []) {
            $this->line('');
            $this->line(' These changes stay on your machine, untouched:');
            foreach ($leftBehindPaths as $path) {
                $this->line("   - {$path}");
            }
        }
        $this->line('');
        $this->warn('  WARNING: make sure the selected files work WITHOUT the changes you');
        $this->warn('  are leaving behind. If they depend on unfinished work, production');
        $this->warn('  will break. ShipIt cannot detect code dependencies for you.');
        $this->line('');
        return $this->confirmYN(' Proceed with deploying only the selected files?', false);
    }

    /**
     * Validate an explicit --files list against the detected changes.
     * Returns the matching change entries, or null (after printing why).
     *
     * @param array<int, array{path:string, orig_path:?string, code:string, label:string}> $entries
     * @param string[] $wanted
     * @return array<int, array{path:string, orig_path:?string, code:string, label:string}>|null
     */
    protected function matchFilesOption(array $entries, array $wanted): ?array
    {
        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry['path']] = $entry;
            if ($entry['orig_path']) {
                $byPath[$entry['orig_path']] = $entry;
            }
        }

        $selected = [];
        foreach ($wanted as $path) {
            $path = ltrim($path, './');
            if (!isset($byPath[$path])) {
                $this->error(" [x] --files entry '{$path}' is not among the changed files. Nothing was committed.");
                $this->line('     Changed files are:');
                foreach ($entries as $entry) {
                    $this->line("       {$entry['path']}");
                }
                return null;
            }
            $selected[$byPath[$path]['path']] = $byPath[$path];
        }

        return array_values($selected);
    }

    /**
     * Split a comma-separated --files option value into clean paths.
     *
     * @return string[]
     */
    protected function parseFilesOption(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
