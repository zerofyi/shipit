<?php

namespace Zerofyi\ShipIt\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Thin wrapper around SSH/SCP execution for one target site.
 *
 * All remote scripts are single-quote escaped before being handed to the
 * local shell, so arbitrary script content (quotes, $variables meant for
 * the REMOTE shell) survives intact. Values interpolated into scripts by
 * callers must still be validated or escapeshellarg()'d at the source;
 * SiteConfig validation guarantees host/user/port/site-dir safety.
 */
class RemoteShell
{
    /**
     * @param array{ssh_host:string, ssh_user:string, ssh_port:int, site_dir:string} $site
     */
    public function __construct(protected array $site) {}

    public function sshTarget(): string
    {
        return "{$this->site['ssh_user']}@{$this->site['ssh_host']}";
    }

    public function sshBase(): string
    {
        $port = (int) $this->site['ssh_port'];
        return "ssh -p {$port} -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=15 " . escapeshellarg($this->sshTarget());
    }

    public function scpBase(): string
    {
        $port = (int) $this->site['ssh_port'];
        return "scp -P {$port} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15";
    }

    /** Absolute path of the site on the server (Hostinger layout). */
    public function sitePath(): string
    {
        return "/home/{$this->site['ssh_user']}/domains/{$this->site['site_dir']}";
    }

    public function remoteHome(): string
    {
        return "/home/{$this->site['ssh_user']}";
    }

    /** Per-site ShipIt data root on the server (outside the web root). */
    public function dataRoot(): string
    {
        return $this->remoteHome() . "/shipit-backups/{$this->site['site_dir']}";
    }

    public function journalPath(): string
    {
        return $this->dataRoot() . '/journal.json';
    }

    public function lockPath(): string
    {
        return $this->dataRoot() . '/.deploy.lock';
    }

    public function snapshotsRoot(): string
    {
        return $this->dataRoot() . '/snapshots';
    }

    public function snapshotPath(string $snapshotId): string
    {
        return $this->snapshotsRoot() . '/' . $snapshotId;
    }

    /**
     * Run a script on the server. The script is passed as a single SSH
     * argument with POSIX-safe quoting.
     */
    public function run(string $remoteScript, int $timeout = 300): ProcessResult
    {
        $quoted = "'" . str_replace("'", "'\\''", $remoteScript) . "'";
        return Process::timeout($timeout)->run($this->sshBase() . ' ' . $quoted);
    }

    /**
     * Write local content to a remote file (stdin -> cat). Used for the
     * journal, manifests and helper scripts. Parent dirs are NOT created;
     * call ensureDataRoot() first when needed.
     */
    public function putContent(string $content, string $remotePath, int $timeout = 60): ProcessResult
    {
        $script = 'cat > ' . escapeshellarg($remotePath);
        $quoted = "'" . str_replace("'", "'\\''", $script) . "'";
        return Process::timeout($timeout)->input($content)->run($this->sshBase() . ' ' . $quoted);
    }

    /** Read a remote file's content, or null when unreadable/missing. */
    public function getContent(string $remotePath, int $timeout = 60): ?string
    {
        $result = $this->run('cat ' . escapeshellarg($remotePath) . ' 2>/dev/null', $timeout);
        if (!$result->successful()) {
            return null;
        }
        return $result->output();
    }

    /** Create the per-site data directories on the server (idempotent). */
    public function ensureDataRoot(): ProcessResult
    {
        $cmd = 'mkdir -p ' . escapeshellarg($this->snapshotsRoot())
            . ' && mkdir -p ' . escapeshellarg($this->dataRoot() . '/helpers');
        return $this->run($cmd, 60);
    }
}
