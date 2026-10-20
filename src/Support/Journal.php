<?php

namespace Zerofyi\ShipIt\Support;

/**
 * The deploy journal: an append-only record of every deploy, rollback,
 * backup and env sync performed against one website.
 *
 * The journal lives ON THE SERVER, per site, at:
 *   /home/<ssh-user>/shipit-backups/<site-dir>/journal.json
 *
 * This class is the pure data layer: decode/encode and entry queries.
 * Transport (reading/writing the file over SSH) lives in the services.
 * Side-effect free and unit tested.
 */
class Journal
{
    public const VERSION = 1;

    public static function empty(): array
    {
        return ['version' => self::VERSION, 'entries' => []];
    }

    /**
     * Decode journal JSON. Any missing/corrupt content degrades to an
     * empty journal rather than an exception: a broken journal must never
     * block a deployment, it just starts a fresh history.
     */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return self::empty();
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return self::empty();
        }

        return ['version' => self::VERSION, 'entries' => array_values($data['entries'])];
    }

    public static function encode(array $journal): string
    {
        return json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Append a new entry. Required keys are filled with sane defaults;
     * 'id' is assigned sequentially (1-based) when not provided.
     */
    public static function append(array $journal, array $entry): array
    {
        $entry = array_merge([
            'id' => count($journal['entries']) + 1,
            'type' => 'deploy',          // deploy | rollback | backup | env_sync
            'status' => 'started',       // started | success | failed
            'at' => date('Y-m-d H:i:s'),
            'branch' => null,
            'from_commit' => null,
            'to_commit' => null,
            'partial' => false,
            'files' => [],
            'backup_id' => null,
            'backup_skipped' => false,
            'failed_step' => null,
            'message' => null,
        ], $entry);

        $journal['entries'][] = $entry;
        return $journal;
    }

    /**
     * Patch the most recent entry (used to flip started -> success/failed
     * and to fill in results discovered as the operation progresses).
     */
    public static function updateLast(array $journal, array $patch): array
    {
        $last = count($journal['entries']) - 1;
        if ($last < 0) {
            return $journal;
        }
        $journal['entries'][$last] = array_merge($journal['entries'][$last], $patch);
        return $journal;
    }

    /** @return array<int, array> All entries of a given type, oldest first. */
    public static function ofType(array $journal, string $type): array
    {
        return array_values(array_filter(
            $journal['entries'],
            fn (array $e) => ($e['type'] ?? null) === $type
        ));
    }

    /** The most recent successful deploy entry, or null. */
    public static function lastSuccessfulDeploy(array $journal): ?array
    {
        $deploys = array_filter(
            $journal['entries'],
            fn (array $e) => ($e['type'] ?? null) === 'deploy' && ($e['status'] ?? null) === 'success'
        );
        if ($deploys === []) {
            return null;
        }
        return $deploys[array_key_last($deploys)];
    }

    /**
     * The commit the site should currently be running, according to the
     * journal: the target of the latest successful deploy or rollback.
     */
    public static function currentKnownCommit(array $journal): ?string
    {
        for ($i = count($journal['entries']) - 1; $i >= 0; $i--) {
            $e = $journal['entries'][$i];
            if (($e['status'] ?? null) === 'success'
                && in_array($e['type'] ?? null, ['deploy', 'rollback'], true)
                && !empty($e['to_commit'])) {
                return $e['to_commit'];
            }
        }
        return null;
    }

    /**
     * Restore points a rollback can offer: successful deploys (and the
     * states captured by standalone backups), newest first. Each item is
     * normalised to: backup_id, at, commit, label.
     *
     * @return array<int, array{backup_id:?string, at:?string, commit:?string, label:string}>
     */
    public static function restorePoints(array $journal): array
    {
        $points = [];
        foreach (array_reverse($journal['entries']) as $e) {
            $type = $e['type'] ?? null;
            if ($type === 'deploy' && ($e['status'] ?? null) === 'success' && !empty($e['backup_id'])) {
                $points[] = [
                    'backup_id' => $e['backup_id'],
                    'at' => $e['at'] ?? null,
                    'commit' => $e['to_commit'] ?? null,
                    'label' => 'Deploy #' . ($e['id'] ?? '?') . ' — ' . ($e['at'] ?? ''),
                ];
            }
            if ($type === 'backup' && ($e['status'] ?? null) === 'success' && !empty($e['backup_id'])) {
                $points[] = [
                    'backup_id' => $e['backup_id'],
                    'at' => $e['at'] ?? null,
                    'commit' => $e['to_commit'] ?? null,
                    'label' => 'Manual backup — ' . ($e['at'] ?? ''),
                ];
            }
        }
        return $points;
    }
}
