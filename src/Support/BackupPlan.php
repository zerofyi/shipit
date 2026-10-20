<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Pure backup maths: snapshot identifiers, retention pruning and parsing
 * of the meta/manifest data produced on the server. Unit tested.
 */
class BackupPlan
{
    /**
     * Snapshot ids are timestamps so plain string sorting is chronological:
     * 20261020-164530
     */
    public static function snapshotId(?\DateTimeInterface $when = null): string
    {
        $when ??= new \DateTimeImmutable();
        return $when->format('Ymd-His');
    }

    public static function isValidSnapshotId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-\d{6}(-\d+)?$/', $id);
    }

    /**
     * Given all snapshot ids, which should be deleted so only the newest
     * $retention remain? The $protect id (usually the one just created)
     * is never returned for deletion.
     *
     * @param string[] $ids
     * @return string[] ids to delete, oldest first
     */
    public static function pruneList(array $ids, int $retention, ?string $protect = null): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => self::isValidSnapshotId((string) $id)));
        sort($ids, SORT_STRING);

        if ($retention < 1) {
            $retention = 1;
        }

        $excess = count($ids) - $retention;
        if ($excess <= 0) {
            return [];
        }

        $delete = array_slice($ids, 0, $excess);
        if ($protect !== null) {
            $delete = array_values(array_filter($delete, fn ($id) => $id !== $protect));
        }
        return $delete;
    }

    /**
     * Parse the key=value meta file the server-side backup script writes.
     *
     * @return array<string,string>
     */
    public static function parseMeta(string $content): array
    {
        $meta = [];
        foreach (preg_split('/\r?\n/', trim($content)) ?: [] as $line) {
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $meta[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        return $meta;
    }

    /**
     * Parse the combined listing the server prints for shipit:backups:
     * blocks of "===SNAP <id>" followed by that snapshot's manifest JSON.
     *
     * @return array<int, array{id:string, manifest:?array}>
     */
    public static function parseSnapshotListing(string $output): array
    {
        $snapshots = [];
        $currentId = null;
        $buffer = [];

        $flush = function () use (&$snapshots, &$currentId, &$buffer) {
            if ($currentId === null) {
                return;
            }
            $json = trim(implode("\n", $buffer));
            $manifest = $json !== '' ? json_decode($json, true) : null;
            $snapshots[] = [
                'id' => $currentId,
                'manifest' => is_array($manifest) ? $manifest : null,
            ];
            $buffer = [];
        };

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^===SNAP\s+(\S+)\s*$/', $line, $m)) {
                $flush();
                $currentId = $m[1];
                continue;
            }
            if ($currentId !== null) {
                $buffer[] = $line;
            }
        }
        $flush();

        // Newest first for display.
        usort($snapshots, fn ($a, $b) => strcmp($b['id'], $a['id']));
        return $snapshots;
    }

    /** Human readable byte size. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
