<?php

namespace Zerofyi\ShipIt\Tests\Fakes;

use Illuminate\Support\Facades\Process;

/**
 * A stateful fake of the world ShipIt talks to: local git on this machine
 * plus a Hostinger server over SSH/SCP.
 *
 * The fake keeps a REAL journal string and snapshot registry: commands
 * write the journal through the fake, and later commands read the
 * updated content back — so multi-step flows (deploy, rollback, env
 * sync) are tested end-to-end without any network.
 */
class FakeServer
{
    // ------------------------------------------------------------- state
    public ?string $journal = null;
    /** @var string[] Every content ever written to journal.json */
    public array $journalWrites = [];
    /** @var array<string, array|null> snapshot id => manifest */
    public array $snapshots = [];
    /** @var string[] Contents written to env-backups/*.env */
    public array $envBackups = [];
    public ?string $uploadedEnv = null;
    /** @var string[] Every command string seen (local + remote) */
    public array $ran = [];
    /** @var string[] Every SSH/SCP command string seen */
    public array $remoteRan = [];

    // ------------------------------------------------------- configuration
    public bool $failBackup = false;
    /** @var array<string,bool> substring => fail that command with exit 1 */
    public array $failOn = [];
    public string $serverHead = 'aaaaaaa1111111serverhead0001';
    public string $localHead = 'bbbbbbb2222222localhead0002';
    public string $porcelain = " M app/Http/Controllers/HomeController.php\n?? app/NewFile.php\n";
    public ?string $originUrl = 'https://github.com/zerofyi/mobilebazar.git';
    public string $branch = 'main';
    public ?string $serverEnv = "APP_NAME=Prod\nAPP_KEY=base64:existingkey\nDB_DATABASE=prod_db\n";
    public string $dbInfo = '{"connection":"mysql","database":"prod_db","tables":12,"supported":true}';
    public bool $lockTakenByOther = false;
    /** Facts printed by the doctor's remote inspection script. */
    public array $doctorFacts = [
        'php_version' => '8.4.0',
        'fn_exec' => '1',
        'fn_symlink' => '1',
        'fn_proc_open' => '1',
        'fn_shell_exec' => '1',
        'has_git' => '1',
        'has_composer' => '1',
        'has_mysqldump' => '1',
        'dir_exists' => '1',
        'dir_writable' => '1',
        'env_exists' => '1',
        'disk_free_kb' => '9999999',
        'inode_free' => '500000',
    ];

    public function register(): void
    {
        Process::fake(fn ($process) => $this->respond($process));
    }

    // ------------------------------------------------------------ assertions
    public function ranContaining(string $needle): bool
    {
        foreach ($this->ran as $cmd) {
            if (str_contains($cmd, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function ranRemoteContaining(string $needle): bool
    {
        foreach ($this->remoteRan as $cmd) {
            if (str_contains($cmd, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Index into remoteRan of the first command containing $needle, or -1. */
    public function remoteIndexOf(string $needle): int
    {
        foreach ($this->remoteRan as $i => $cmd) {
            if (str_contains($cmd, $needle)) {
                return $i;
            }
        }
        return -1;
    }

    /** The decoded journal entries as the server currently holds them. */
    public function journalEntries(): array
    {
        if ($this->journal === null) {
            return [];
        }
        $decoded = json_decode($this->journal, true);
        return $decoded['entries'] ?? [];
    }

    // -------------------------------------------------------------- plumbing
    public function respond($process)
    {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $this->ran[] = $cmd;

        foreach ($this->failOn as $needle => $on) {
            if ($on && str_contains($cmd, $needle)) {
                return Process::result('', "injected failure at {$needle}", 1);
            }
        }

        if (str_contains($cmd, 'ssh -p') || str_contains($cmd, 'scp -P')) {
            $this->remoteRan[] = $cmd;
            return $this->respondRemote($cmd, $process->input !== null ? (string) $process->input : null);
        }

        return $this->respondLocal($cmd);
    }

    private function respondLocal(string $cmd)
    {
        return match (true) {
            str_contains($cmd, 'which git'), str_contains($cmd, 'where git'),
            str_contains($cmd, 'which npm'), str_contains($cmd, 'where npm') => Process::result(''),
            str_contains($cmd, 'git rev-parse --is-inside-work-tree') => Process::result("true\n"),
            str_contains($cmd, 'git status --porcelain') => Process::result($this->porcelain),
            str_contains($cmd, 'git branch --show-current') => Process::result($this->branch . "\n"),
            str_contains($cmd, 'git config --get remote.origin.url') => $this->originUrl !== null
                ? Process::result($this->originUrl . "\n")
                : Process::result('', '', 1),
            str_contains($cmd, 'ls-remote') => Process::result($this->localHead . "\tHEAD\n"),
            str_contains($cmd, 'git rev-parse HEAD') => Process::result($this->localHead . "\n"),
            default => Process::result(''),
        };
    }

    private function respondRemote(string $cmd, ?string $input)
    {
        // -- File writes (stdin -> cat > path) ------------------------------
        if (str_contains($cmd, 'cat >') && str_contains($cmd, 'journal.json')) {
            $this->journal = $input;
            $this->journalWrites[] = (string) $input;
            return Process::result('');
        }
        if (str_contains($cmd, 'cat >') && preg_match('#/snapshots/(\d{8}-\d{6})/manifest\.json#', $cmd, $m)) {
            $this->snapshots[$m[1]] = json_decode((string) $input, true);
            return Process::result('');
        }
        if (str_contains($cmd, 'cat >') && str_contains($cmd, 'env-backups/')) {
            $this->envBackups[] = (string) $input;
            return Process::result('');
        }
        if (str_contains($cmd, 'cat >') && str_contains($cmd, 'db-tool.php')) {
            return Process::result('');
        }
        if (str_contains($cmd, 'cat >') && preg_match('#domains/[^/\']+/\.env\'#', $cmd)) {
            $this->uploadedEnv = $input;
            $this->serverEnv = $input;
            return Process::result('');
        }

        // -- Journal read ----------------------------------------------------
        if (str_contains($cmd, 'journal.json')) {
            return $this->journal !== null ? Process::result($this->journal) : Process::result('', '', 1);
        }

        // -- Doctor inspection (check BEFORE db-tool info: the doctor script
        //    itself contains the words "db-tool.php info") -----------------
        if (str_contains($cmd, 'php_version=')) {
            $lines = [];
            foreach ($this->doctorFacts as $k => $v) {
                $lines[] = "{$k}={$v}";
            }
            $lines[] = 'db_info=' . $this->dbInfo;
            return Process::result(implode("\n", $lines) . "\n");
        }

        // -- Server .env presence + read -------------------------------------
        if (str_contains($cmd, 'test -f') && str_contains($cmd, '.env')) {
            return Process::result($this->serverEnv !== null ? "exists\n" : "missing\n");
        }
        // Remote commands arrive with escapeshellarg-mangled quotes
        // ('\'' sequences), so match on stable fragments only.
        if (str_contains($cmd, 'cat') && str_contains($cmd, 'domains/') && str_contains($cmd, '/.env') && str_contains($cmd, '2>/dev/null')) {
            return $this->serverEnv !== null ? Process::result($this->serverEnv) : Process::result('', '', 1);
        }

        // -- Deploy lock -------------------------------------------------------
        if (str_contains($cmd, '.deploy.lock') && str_contains($cmd, 'printf')) {
            return Process::result($this->lockTakenByOther ? "LOCKED\n" : "ACQUIRED\n");
        }
        if (str_contains($cmd, '.deploy.lock')) {
            return Process::result('');
        }

        // -- Handshake / repo state -------------------------------------------
        if (str_contains($cmd, 'test -d') && str_contains($cmd, '/.git')) {
            return Process::result("pull\n");
        }
        if (str_contains($cmd, 'test -d') && str_contains($cmd, 'exists')) {
            return Process::result("exists\n");
        }
        // -- Backup script (check BEFORE git rev-parse: the script itself
        //    contains a rev-parse call for the commit hash) ----------------
        if (str_contains($cmd, 'BACKUP_OK')) {
            if ($this->failBackup) {
                return Process::result('', 'backup exploded', 1);
            }
            if (preg_match('#/snapshots/(\d{8}-\d{6})#', $cmd, $m)) {
                $this->snapshots[$m[1]] ??= null;
            }
            return Process::result("BACKUP_OK\n");
        }
        if (str_contains($cmd, 'meta.txt')) {
            return Process::result(implode("\n", [
                'commit=' . $this->serverHead,
                'db_method=mysqldump',
                'db_name=prod_db',
                'db_tables=12',
                'size_db=1000',
                'size_storage=2000',
                'size_build=3000',
                'has_env=1',
            ]) . "\n");
        }

        if (str_contains($cmd, 'git rev-parse HEAD')) {
            return Process::result($this->serverHead . "\n");
        }

        // -- Snapshot listing / deletion ---------------------------------------
        if (str_contains($cmd, '===SNAP')) {
            $out = '';
            foreach ($this->snapshots as $id => $manifest) {
                $out .= "===SNAP {$id}\n";
                if ($manifest !== null) {
                    $out .= json_encode($manifest) . "\n";
                }
            }
            return Process::result($out);
        }
        // Only the dedicated delete command targets a snapshot dir with
        // rm -rf directly; other commands (asset restore) mention both
        // "rm -rf" (for public/build) and a snapshot path in one string.
        if (preg_match('#rm -rf\s+\S*/snapshots/(\d{8}-\d{6})\'#', $cmd, $m)) {
            unset($this->snapshots[$m[1]]);
            return Process::result('');
        }

        // -- db-tool -------------------------------------------------------------
        if (str_contains($cmd, 'db-tool.php') && str_contains($cmd, ' info ')) {
            return Process::result($this->dbInfo . "\n");
        }
        if (str_contains($cmd, 'db-tool.php') && str_contains($cmd, ' restore ')) {
            return Process::result('{"statements":42}' . "\n");
        }

        // -- Everything else (trust, sync, pipeline, rollback steps) ------------
        return Process::result('');
    }
}
