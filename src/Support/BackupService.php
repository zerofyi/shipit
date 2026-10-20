<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Orchestrates everything that lives on the server around a site:
 * snapshots (pre-deploy backups), the deploy journal and the deploy lock.
 *
 * Every method talks to the server through RemoteShell only, so the whole
 * layer is testable with Process::fake() — no live SSH needed.
 */
class BackupService
{
    /** Lock is considered stale (previous run died) after this many seconds. */
    public const LOCK_STALE_SECONDS = 7200;

    public function __construct(protected RemoteShell $remote) {}

    // ---------------------------------------------------------------- Journal

    public function readJournal(): array
    {
        return Journal::decode($this->remote->getContent($this->remote->journalPath()));
    }

    public function writeJournal(array $journal): bool
    {
        $this->remote->ensureDataRoot();
        $result = $this->remote->putContent(Journal::encode($journal), $this->remote->journalPath());
        return $result->successful();
    }

    public function appendJournal(array $entry, array $patchLast = []): array
    {
        $journal = $this->readJournal();
        $journal = Journal::append($journal, $entry);
        if ($patchLast !== []) {
            $journal = Journal::updateLast($journal, $patchLast);
        }
        $this->writeJournal($journal);
        return $journal;
    }

    public function patchLastJournalEntry(array $patch): array
    {
        $journal = $this->readJournal();
        $journal = Journal::updateLast($journal, $patch);
        $this->writeJournal($journal);
        return $journal;
    }

    // ------------------------------------------------------------------- Lock

    /**
     * Try to take the per-site deploy lock. Prevents two terminals (or a
     * deploy and a rollback) from interleaving writes on the same site.
     * Other sites on the same account have their own locks.
     */
    public function acquireLock(string $owner): bool
    {
        $this->remote->ensureDataRoot();
        $lock = $this->remote->lockPath();
        $stale = self::LOCK_STALE_SECONDS;
        $script = implode(' ', [
            'if [ -f ' . escapeshellarg($lock) . ' ]; then',
            'AGE=$(( $(date +%s) - $(stat -c %Y ' . escapeshellarg($lock) . ' 2>/dev/null || echo 0) ));',
            'if [ "$AGE" -lt ' . $stale . ' ]; then echo LOCKED; exit 0; fi;',
            'fi;',
            'printf %s ' . escapeshellarg($owner . ' @ ' . date('Y-m-d H:i:s')) . ' > ' . escapeshellarg($lock),
            '; echo ACQUIRED',
        ]);
        $result = $this->remote->run($script, 60);
        return $result->successful() && str_contains($result->output(), 'ACQUIRED');
    }

    public function releaseLock(): void
    {
        $this->remote->run('rm -f ' . escapeshellarg($this->remote->lockPath()), 30);
    }

    // --------------------------------------------------------------- Snapshots

    /**
     * Create a full snapshot of the site as it exists on the server NOW.
     *
     * @param callable(string):void|null $log Progress logger.
     * @return array{ok:bool, id:?string, manifest:?array, error:?string}
     */
    public function createSnapshot(?callable $log = null, string $reason = 'deploy'): array
    {
        $log ??= fn (string $m) => null;

        $this->remote->ensureDataRoot();
        $helper = $this->uploadDbTool();
        if (!$helper) {
            return ['ok' => false, 'id' => null, 'manifest' => null, 'error' => 'Could not upload the database helper script to the server.'];
        }

        $id = BackupPlan::snapshotId();
        $snap = $this->remote->snapshotPath($id);
        $site = $this->remote->sitePath();
        $tool = $this->remote->dataRoot() . '/helpers/db-tool.php';

        $log("Snapshotting {$this->remote->sitePath()} -> {$id}");

        $script = $this->buildBackupScript($site, $snap, $tool);
        $result = $this->remote->run($script, 900);
        if (!$result->successful()) {
            return ['ok' => false, 'id' => null, 'manifest' => null,
                'error' => 'Server backup script failed: ' . trim($result->errorOutput() ?: $result->output())];
        }

        $meta = BackupPlan::parseMeta((string) $this->remote->getContent($snap . '/meta.txt'));
        $manifest = [
            'id' => $id,
            'created_at' => date('Y-m-d H:i:s'),
            'reason' => $reason,
            'site_dir' => basename($this->remote->sitePath()),
            'commit' => ($meta['commit'] ?? '') !== '' ? $meta['commit'] : null,
            'database' => [
                'method' => $meta['db_method'] ?? 'none',
                'name' => ($meta['db_name'] ?? '') !== '' ? $meta['db_name'] : null,
                'tables' => isset($meta['db_tables']) ? (int) $meta['db_tables'] : 0,
            ],
            'files' => [
                'db_bytes' => (int) ($meta['size_db'] ?? 0),
                'storage_bytes' => (int) ($meta['size_storage'] ?? 0),
                'build_bytes' => (int) ($meta['size_build'] ?? 0),
                'has_env' => ($meta['has_env'] ?? '0') === '1',
                'has_storage' => ($meta['size_storage'] ?? '0') !== '0',
                'has_build' => ($meta['size_build'] ?? '0') !== '0',
            ],
        ];

        $this->remote->putContent(
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            $snap . '/manifest.json'
        );

        $log("Snapshot {$id} complete (database: {$manifest['database']['method']}).");
        return ['ok' => true, 'id' => $id, 'manifest' => $manifest, 'error' => null];
    }

    /** Upload the server-side db-tool helper. Returns success. */
    public function uploadDbTool(): bool
    {
        $source = __DIR__ . '/../../resources/server/db-tool.php';
        $content = is_file($source) ? file_get_contents($source) : false;
        if ($content === false) {
            return false;
        }
        $result = $this->remote->putContent($content, $this->remote->dataRoot() . '/helpers/db-tool.php');
        return $result->successful();
    }

    /** @return array<int, array{id:string, manifest:?array}> Newest first. */
    public function listSnapshots(): array
    {
        $root = $this->remote->snapshotsRoot();
        $script = 'for d in $(ls -1 ' . escapeshellarg($root) . ' 2>/dev/null); do '
            . 'echo "===SNAP $d"; '
            . 'cat ' . escapeshellarg($root) . '/"$d"/manifest.json 2>/dev/null; '
            . 'done';
        $result = $this->remote->run($script, 120);
        if (!$result->successful()) {
            return [];
        }
        return BackupPlan::parseSnapshotListing($result->output());
    }

    public function deleteSnapshot(string $id): bool
    {
        if (!BackupPlan::isValidSnapshotId($id)) {
            return false;
        }
        $result = $this->remote->run('rm -rf ' . escapeshellarg($this->remote->snapshotPath($id)), 120);
        return $result->successful();
    }

    /** Delete snapshots beyond the retention limit. Returns deleted ids. */
    public function prune(int $retention, ?string $protectId = null): array
    {
        $snapshots = $this->listSnapshots();
        $ids = array_map(fn ($s) => $s['id'], $snapshots);
        $delete = BackupPlan::pruneList($ids, $retention, $protectId);
        foreach ($delete as $id) {
            $this->deleteSnapshot($id);
        }
        return $delete;
    }

    // ------------------------------------------------------------ Bash builders

    /**
     * The server-side backup script. Paths are embedded with
     * escapeshellarg(); everything else is static, reviewed shell.
     */
    public function buildBackupScript(string $sitePath, string $snapPath, string $toolPath): string
    {
        $site = escapeshellarg($sitePath);
        $snap = escapeshellarg($snapPath);
        $tool = escapeshellarg($toolPath);

        return implode("\n", [
            'set -o pipefail',
            "mkdir -p {$snap}",
            "cd {$site} || exit 10",
            'COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")',
            '[ -f .env ] && cp .env ' . $snap . '/env.snapshot || true',
            '[ -d storage/app ] && tar -czf ' . $snap . '/storage.tar.gz storage/app || true',
            '[ -d public/build ] && tar -czf ' . $snap . '/build.tar.gz public/build || true',
            'DBMETHOD=none; DBNAME=""; DBTABLES=0',
            'if [ -f .env ]; then',
            '  INFO=$(php ' . $tool . ' info ' . $site . ' 2>/dev/null || echo "{}")',
            '  CONN=$(printf %s "$INFO" | grep -o \'"connection":"[^"]*"\' | cut -d\'"\' -f4)',
            '  DBNAME=$(printf %s "$INFO" | grep -o \'"database":"[^"]*"\' | cut -d\'"\' -f4)',
            '  DBTABLES=$(printf %s "$INFO" | grep -o \'"tables":[0-9]*\' | cut -d: -f2); DBTABLES=${DBTABLES:-0}',
            '  if [ "$CONN" = "mysql" ] && command -v mysqldump >/dev/null 2>&1; then',
            '    DF=$(mktemp)',
            '    if php ' . $tool . ' defaults ' . $site . ' "$DF" >/dev/null 2>&1; then',
            '      if mysqldump --defaults-extra-file="$DF" --single-transaction --skip-lock-tables --routines --triggers "$DBNAME" | gzip > ' . $snap . '/db.sql.gz; then DBMETHOD=mysqldump; fi',
            '    fi',
            '    rm -f "$DF"',
            '  fi',
            '  if [ "$DBMETHOD" = "none" ]; then',
            '    if php ' . $tool . ' dump ' . $site . ' ' . $snap . '/db.sql.gz >/dev/null 2>&1; then DBMETHOD=php; else rm -f ' . $snap . '/db.sql.gz; fi',
            '  fi',
            'fi',
            'SZ() { stat -c %s "$1" 2>/dev/null || echo 0; }',
            '{',
            '  echo "commit=$COMMIT"',
            '  echo "db_method=$DBMETHOD"',
            '  echo "db_name=$DBNAME"',
            '  echo "db_tables=$DBTABLES"',
            '  echo "size_db=$(SZ ' . $snap . '/db.sql.gz)"',
            '  echo "size_storage=$(SZ ' . $snap . '/storage.tar.gz)"',
            '  echo "size_build=$(SZ ' . $snap . '/build.tar.gz)"',
            '  if [ -f ' . $snap . '/env.snapshot ]; then echo "has_env=1"; else echo "has_env=0"; fi',
            '} > ' . $snap . '/meta.txt',
            'echo BACKUP_OK',
        ]);
    }
}
