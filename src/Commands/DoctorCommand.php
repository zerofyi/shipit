<?php

namespace Zerofyi\ShipIt\Commands;

use Zerofyi\ShipIt\Support\BackupService;
use Zerofyi\ShipIt\Support\RemoteShell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * shipit:doctor — pre-flight inspection of everything a deployment
 * depends on, locally and on the server, with the exact fix printed for
 * every problem found. Any FAIL blocks nothing by itself, but the exit
 * code is non-zero so scripts (and humans) can gate on it.
 */
class DoctorCommand extends BasePushCommand
{
    protected $signature = 'shipit:doctor
                            {--site= : Site profile from config/shipit.php (default: .env values)}';

    protected $description = 'Pre-flight check: verifies local tooling, server access, PHP functions, disk/inode headroom and database reachability for a site.';

    /** @var array<int, array{status:string, label:string, detail:string, fix:?string}> */
    private array $rows = [];

    private bool $hasFailure = false;

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>[->] ShipIt Doctor — pre-flight inspection</>');
        $this->line('');

        $env = $this->resolveSiteOrFail($this->option('site'), 'full');
        if (!$env) {
            return Command::FAILURE;
        }

        $this->checkLocal($env);
        $this->checkRemote($env);

        $this->line('');
        foreach ($this->rows as $row) {
            $icon = match ($row['status']) {
                'pass' => '<fg=green>[PASS]</>',
                'warn' => '<fg=yellow>[WARN]</>',
                default => '<fg=red>[FAIL]</>',
            };
            $this->line(" {$icon} {$row['label']}" . ($row['detail'] !== '' ? " — {$row['detail']}" : ''));
            if ($row['fix']) {
                $this->line("        Fix: {$row['fix']}");
            }
        }
        $this->line('');

        if ($this->hasFailure) {
            $this->error('[x] Doctor found problems that will block or break deployments. Fix the FAIL items above.');
            return Command::FAILURE;
        }

        $this->line('<fg=green;options=bold>[==] Doctor: this site is ready to deploy.</>');
        return Command::SUCCESS;
    }

    private function add(string $status, string $label, string $detail = '', ?string $fix = null): void
    {
        $this->rows[] = ['status' => $status, 'label' => $label, 'detail' => $detail, 'fix' => $fix];
        if ($status === 'fail') {
            $this->hasFailure = true;
        }
    }

    private function checkLocal(array $env): void
    {
        // PHP version
        $phpVersion = PHP_VERSION;
        $this->add(
            version_compare($phpVersion, '8.1.0', '>=') ? 'pass' : 'fail',
            'Local PHP >= 8.1',
            $phpVersion,
            'Upgrade your local PHP to 8.1 or newer.'
        );

        // Git binary
        $gitCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where git' : 'which git';
        $this->add(
            Process::run($gitCmd)->successful() ? 'pass' : 'fail',
            'Local git binary',
            '',
            'Install Git and make sure it is on your PATH.'
        );

        // npm (warning only — mirrors the deploy pipeline behaviour)
        $npmCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where npm' : 'which npm';
        $hasPackageJson = file_exists(base_path('package.json'));
        if ($hasPackageJson) {
            $this->add(
                Process::run($npmCmd)->successful() ? 'pass' : 'warn',
                'Local npm (frontend builds)',
                Process::run($npmCmd)->successful() ? '' : 'package.json exists but npm was not found — deploys would ship stale assets',
                'Install Node.js/npm locally, or deploy with --skip-assets if the server builds assets.'
            );
        }

        // Repository reachable from here
        $reach = Process::env([
            'GITHUB_TOKEN' => null,
            'GIT_ASKPASS' => 'echo',
            'GIT_TERMINAL_PROMPT' => '0',
        ])->run('git -c credential.helper= ls-remote -h ' . escapeshellarg((string) $env['repo_url']));
        $this->add(
            $reach->successful() ? 'pass' : 'warn',
            'Repository reachable from this machine',
            $reach->successful() ? 'public access or cached credentials' : 'private repository (normal — the server trust flow will handle access)',
            null
        );
    }

    private function checkRemote(array $env): void
    {
        $remote = new RemoteShell($env);
        $backups = new BackupService($remote);
        $site = $remote->sitePath();

        $backups->uploadDbTool();
        $tool = $remote->dataRoot() . '/helpers/db-tool.php';

        $script = implode("\n", [
            'echo "php_version=$(php -r \'echo PHP_VERSION;\' 2>/dev/null)"',
            'for f in exec symlink proc_open shell_exec; do echo "fn_$f=$(php -r "echo function_exists(\'$f\') ? \'1\' : \'0\';" 2>/dev/null)"; done',
            'command -v git >/dev/null 2>&1 && echo "has_git=1" || echo "has_git=0"',
            'command -v composer >/dev/null 2>&1 && echo "has_composer=1" || echo "has_composer=0"',
            'command -v mysqldump >/dev/null 2>&1 && echo "has_mysqldump=1" || echo "has_mysqldump=0"',
            'test -d ' . escapeshellarg($site) . ' && echo "dir_exists=1" || echo "dir_exists=0"',
            'test -w ' . escapeshellarg($site) . ' && echo "dir_writable=1" || echo "dir_writable=0"',
            'test -f ' . escapeshellarg($site . '/.env') . ' && echo "env_exists=1" || echo "env_exists=0"',
            'echo "disk_free_kb=$(df -Pk ' . escapeshellarg($site) . ' 2>/dev/null | tail -1 | awk \'{print $4}\')"',
            'echo "inode_free=$(df -iPk ' . escapeshellarg($site) . ' 2>/dev/null | tail -1 | awk \'{print $4}\')"',
            'echo "db_info=$(php ' . escapeshellarg($tool) . ' info ' . escapeshellarg($site) . ' 2>/dev/null)"',
        ]);

        $result = $remote->run($script, 120);
        if (!$result->successful()) {
            $this->add('fail', 'SSH connection to the server', 'could not execute the inspection script', 'Check HOSTINGER_SSH_* values, that SSH Access is enabled in hPanel, and that your key is authorized. Hostinger SSH port is usually 65002.');
            return;
        }

        $facts = [];
        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $facts[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        $this->add('pass', 'SSH connection to the server', "{$env['ssh_user']}@{$env['ssh_host']}:{$env['ssh_port']}");

        $serverPhp = $facts['php_version'] ?? '';
        $this->add(
            $serverPhp !== '' && version_compare($serverPhp, '8.1.0', '>=') ? 'pass' : 'fail',
            'Server PHP >= 8.1',
            $serverPhp ?: 'not detected',
            'Switch the site\'s PHP version to 8.1+ in hPanel > PHP Configuration.'
        );

        foreach (['exec', 'symlink', 'proc_open', 'shell_exec'] as $fn) {
            $ok = ($facts["fn_{$fn}"] ?? '0') === '1';
            $this->add(
                $ok ? 'pass' : 'warn',
                "Server PHP function {$fn}() enabled",
                $ok ? '' : 'disabled',
                "Enable it in hPanel > PHP Configuration by removing '{$fn}' from disable_functions. ShipIt has fallbacks for storage:link, but artisan features may need it."
            );
        }

        $this->add(($facts['has_git'] ?? '0') === '1' ? 'pass' : 'fail', 'Server git available', '', 'Git is required on the server. It is included on Hostinger Premium plans and above.');
        $this->add(($facts['has_composer'] ?? '0') === '1' ? 'pass' : 'fail', 'Server composer available', '', 'Composer is required on the server for dependency installs.');
        $this->add(
            ($facts['has_mysqldump'] ?? '0') === '1' ? 'pass' : 'warn',
            'Server mysqldump (fast database backups)',
            ($facts['has_mysqldump'] ?? '0') === '1' ? '' : 'not found — ShipIt will use its PHP-based dump fallback (works, slightly slower)',
            null
        );

        $this->add(($facts['dir_exists'] ?? '0') === '1' ? 'pass' : 'fail', 'Site directory exists', $site, 'Create the domain/subdomain in hPanel first so the directory exists.');
        $this->add(($facts['dir_writable'] ?? '0') === '1' ? 'pass' : 'fail', 'Site directory writable', '', 'Check the directory permissions/ownership in hPanel File Manager.');

        $diskKb = (int) ($facts['disk_free_kb'] ?? 0);
        $this->add(
            $diskKb > 512 * 1024 ? 'pass' : 'warn',
            'Server free disk space',
            $diskKb > 0 ? round($diskKb / 1024, 1) . ' MB free' : 'unknown',
            'Free space or reduce shipit.backup.retention in config/shipit.php — snapshots need room to exist.'
        );

        $inodeFree = (int) ($facts['inode_free'] ?? 0);
        $this->add(
            $inodeFree === 0 || $inodeFree > 10000 ? 'pass' : 'warn',
            'Server free inodes',
            $inodeFree > 0 ? number_format($inodeFree) . ' free' : 'unknown',
            'Snapshot tarballs use very few inodes by design, but a nearly-full inode quota breaks uploads and caching too.'
        );

        if (($facts['env_exists'] ?? '0') !== '1') {
            $this->add('warn', 'Server .env present', 'not yet — the first deploy will provision it from .env.production', null);
            return;
        }

        $dbInfo = json_decode($facts['db_info'] ?? '', true);
        if (is_array($dbInfo) && ($dbInfo['connection'] ?? 'none') !== 'none') {
            $supported = $dbInfo['supported'] ?? true;
            $this->add(
                $supported ? 'pass' : 'warn',
                'Database reachable from the server',
                ($dbInfo['connection'] ?? '?') . ' / ' . ($dbInfo['database'] ?? '?') . ' / ' . ($dbInfo['tables'] ?? 0) . ' tables',
                $supported ? null : 'ShipIt database backups support MySQL/MariaDB and SQLite. Other drivers deploy fine but are not snapshotted.'
            );
        } else {
            $this->add('fail', 'Database reachable from the server', 'db-tool could not read database info', 'Check the DB_* values in the server .env (hPanel > Databases for the exact credentials).');
        }
    }
}
