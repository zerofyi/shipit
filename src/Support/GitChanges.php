<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Pure helpers for selective deployments: parsing `git status --porcelain`
 * output and parsing the user's file selection ("1,3,5", "2-4", "all").
 *
 * Side-effect free and unit tested.
 */
class GitChanges
{
    /**
     * Parse `git status --porcelain` (v1) output into change entries.
     *
     * @return array<int, array{path:string, orig_path:?string, code:string, label:string}>
     */
    public static function parsePorcelain(string $output): array
    {
        $entries = [];

        // NOTE: never left-trim the output — the first porcelain line may
        // legitimately start with a space (" M path"), and trimming it
        // would shift every column of that line.
        foreach (preg_split('/\r?\n/', rtrim($output, "\r\n")) ?: [] as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $code = substr($line, 0, 2);
            $pathPart = substr($line, 3);
            $origPath = null;

            if (str_contains($pathPart, ' -> ')) {
                [$origRaw, $newRaw] = explode(' -> ', $pathPart, 2);
                $origPath = self::unquote(trim($origRaw));
                $path = self::unquote(trim($newRaw));
            } else {
                $path = self::unquote(trim($pathPart));
            }

            if ($path === '') {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'orig_path' => $origPath,
                'code' => $code,
                'label' => self::labelFor($code),
            ];
        }

        return $entries;
    }

    /**
     * Git quotes paths containing special characters C-style ("a\"b").
     */
    public static function unquote(string $path): string
    {
        if (strlen($path) >= 2 && $path[0] === '"' && str_ends_with($path, '"')) {
            $inner = substr($path, 1, -1);
            return stripcslashes($inner);
        }
        return $path;
    }

    public static function labelFor(string $code): string
    {
        return match (true) {
            $code === '??' => 'Untracked (new file)',
            str_contains($code, 'R') => 'Renamed',
            str_contains($code, 'A') => 'Added',
            str_contains($code, 'D') => 'Deleted',
            str_contains($code, 'M') => 'Modified',
            str_contains($code, 'U') => 'Conflicted',
            default => 'Changed',
        };
    }

    /**
     * Only these change codes are safe to offer for selective staging.
     * Conflicted entries (U) must be resolved by the user first.
     */
    public static function isSelectable(array $entry): bool
    {
        return !str_contains($entry['code'], 'U');
    }

    /**
     * Does a changed path require a frontend asset rebuild when deployed
     * selectively? (Anything under resources/, plus build config files.)
     */
    public static function requiresAssetBuild(string $path): bool
    {
        $path = ltrim($path, './');
        return str_starts_with($path, 'resources/')
            || in_array($path, ['vite.config.js', 'vite.config.ts', 'package.json', 'package-lock.json', 'tailwind.config.js', 'tailwind.config.ts', 'postcss.config.js'], true);
    }

    /**
     * Parse a user selection string against a 1-based list of $count items.
     * Accepts: "all", "1,3,5", "2-4", mixed "1, 3-5, 8".
     *
     * @return array{indices: array<int,int>, error: ?string}
     */
    public static function parseSelection(string $input, int $count): array
    {
        $input = trim($input);

        if ($input === '') {
            return ['indices' => [], 'error' => 'No selection given.'];
        }

        if (strtolower($input) === 'all') {
            return ['indices' => range(0, $count - 1), 'error' => null];
        }

        $indices = [];
        foreach (explode(',', $input) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $from = (int) $m[1];
                $to = (int) $m[2];
                if ($from < 1 || $to > $count || $from > $to) {
                    return ['indices' => [], 'error' => "Range '{$part}' is out of bounds (1-{$count})."];
                }
                for ($i = $from; $i <= $to; $i++) {
                    $indices[] = $i - 1;
                }
                continue;
            }

            if (!ctype_digit($part)) {
                return ['indices' => [], 'error' => "'{$part}' is not a valid file number."];
            }

            $n = (int) $part;
            if ($n < 1 || $n > $count) {
                return ['indices' => [], 'error' => "File number {$n} is out of bounds (1-{$count})."];
            }
            $indices[] = $n - 1;
        }

        $indices = array_values(array_unique($indices));
        sort($indices);

        if ($indices === []) {
            return ['indices' => [], 'error' => 'No files selected.'];
        }

        return ['indices' => $indices, 'error' => null];
    }
}
