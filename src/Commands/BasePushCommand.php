<?php

namespace Zerofyi\ShipIt\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Exception;

abstract class BasePushCommand extends Command
{
    /**
     * Shared Process Runtime Timeout (5 Minutes max for npm/composer updates)
     */
    protected const PROCESS_TIMEOUT = 300;

    /**
     * Parse and validate required local .env variables with strict security checks.
     */
    protected function validateLocalEnvironment(bool $requireHostinger = false): ?array
    {
        $config = [
            'repo_url' => env('GITHUB_REPO_URL'),
        ];

        if (empty($config['repo_url']) || !filter_var($config['repo_url'], FILTER_DEFAULT)) {
            $this->error('❌ Missing or invalid required environment variable: GITHUB_REPO_URL');
            $this->line('💡 Please add the following entry to your local .env file:');
            $this->warn('   GITHUB_REPO_URL=https://github.com/username/repository.git');
            return null;
        }

        if ($requireHostinger) {
            $config['ssh_host'] = env('HOSTINGER_SSH_HOST');
            $config['ssh_user'] = env('HOSTINGER_SSH_USERNAME');
            $config['ssh_port'] = (int) env('HOSTINGER_SSH_PORT', 22);
            $config['site_dir'] = env('HOSTINGER_SITE_DIR');

            $missing = [];
            foreach ([
                'ssh_host' => 'HOSTINGER_SSH_HOST',
                'ssh_user' => 'HOSTINGER_SSH_USERNAME',
                'site_dir' => 'HOSTINGER_SITE_DIR'
            ] as $key => $envKey) {
                if (empty($config[$key]) || trim($config[$key]) === '') {
                    $missing[] = $envKey;
                }
            }

            if (!empty($missing)) {
                $this->error('❌ Missing required Hostinger environment variables.');
                $this->line('💡 Please add these missing entries to your local .env file:');
                foreach ($missing as $envKey) {
                    $this->warn("   {$envKey}=value");
                }
                return null;
            }

            // Strict Anti-Destruction Check: Ensure directory name contains no hazardous path characters
            if (str_contains($config['site_dir'], '..') || str_contains($config['site_dir'], '/') || str_contains($config['site_dir'], '\\')) {
                $this->error('❌ Critical Security Alert: HOSTINGER_SITE_DIR contains unsafe path characters.');
                return null;
            }
        }

        return $config;
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

        $this->info('📦 package.json detected. Verifying local Node environment...');

        // Cross-platform check for npm binary presence
        $npmCheckCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where npm' : 'which npm';
        try {
            $npmCheck = Process::run($npmCheckCmd);
            if (!$npmCheck->successful()) {
                $this->warn('⚠️  npm binary not found on your local computer. Skipping asset compilation.');
                return true;
            }
        } catch (Exception $e) {
            $this->warn('⚠️  Failed to look up local npm environment path. Proceeding cautiously...');
            return true;
        }

        try {
            // Auto-install missing local dependencies safely
            if (!is_dir(base_path('node_modules'))) {
                $this->info('📥 Local node_modules missing. Running npm install...');
                $install = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm install');

                if (!$install->successful()) {
                    $this->error('❌ Local "npm install" failed.');
                    $this->line($install->errorOutput());
                    return false;
                }
            }

            // Run localized optimization production compilation build
            $this->info('🔨 Compiling frontend assets via npm run build...');
            $build = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm run build');

            if (!$build->successful()) {
                $this->error('❌ Local frontend compilation failed.');
                $this->line($build->errorOutput());
                return false;
            }
        } catch (Exception $e) {
            $this->error('❌ Fatal error encountered during local frontend build pipeline: ' . $e->getMessage());
            return false;
        }

        $this->info('✅ Frontend assets built successfully.');
        return true;
    }

    /**
     * Enforces typed validation for interactive prompts to prevent script runaways.
     */
    protected function confirmYN(string $question, bool $default = false): bool
    {
        $hint = $default ? '(Y/n)' : '(y/N)';
        $answer = $this->ask("{$question} {$hint}");

        if ($answer === null || trim($answer) === '') {
            return $default;
        }

        return in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /**
     * Print diagnostic console information if the debug flag is active.
     */
    protected function debug(string $message): void
    {
        if ($this->option('debug')) {
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
}
