<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Local .env parsing and comparison for shipit:env (env synchronisation).
 * Values are only ever compared and counted locally; commands display key
 * names and change kinds, never values. Unit tested.
 */
class EnvFile
{
    /**
     * Parse .env content into an ordered key => value map (values raw,
     * quotes stripped, comments ignored) — same subset the server-side
     * db-tool parser understands.
     *
     * @return array<string,string>
     */
    public static function parse(string $content): array
    {
        $vars = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'")) {
                $quote = $val[0];
                if (str_ends_with($val, $quote)) {
                    $val = substr($val, 1, -1);
                }
                if ($quote === '"') {
                    $val = str_replace(['\\n', '\\r'], ["\n", "\r"], $val);
                }
            } else {
                $hash = strpos($val, ' #');
                if ($hash !== false) {
                    $val = rtrim(substr($val, 0, $hash));
                }
            }
            if ($key !== '') {
                $vars[$key] = $val;
            }
        }
        return $vars;
    }

    /**
     * Compare two parsed .env maps.
     *
     * @param array<string,string> $local  The desired state (.env.production)
     * @param array<string,string> $server The current state (server .env)
     * @return array{added: string[], changed: string[], removed: string[], unchanged: int}
     *   added     — keys present locally but not on the server
     *   changed   — keys present in both with different values
     *   removed   — keys on the server that a sync would delete
     *   unchanged — count of identical keys
     */
    public static function diff(array $local, array $server): array
    {
        $added = [];
        $changed = [];
        $unchanged = 0;

        foreach ($local as $key => $value) {
            if (!array_key_exists($key, $server)) {
                $added[] = $key;
            } elseif ($server[$key] !== $value) {
                $changed[] = $key;
            } else {
                $unchanged++;
            }
        }

        $removed = array_values(array_diff(array_keys($server), array_keys($local)));

        sort($added);
        sort($changed);
        sort($removed);

        return [
            'added' => $added,
            'changed' => $changed,
            'removed' => $removed,
            'unchanged' => $unchanged,
        ];
    }
}
