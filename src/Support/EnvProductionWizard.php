<?php

namespace Zerofyi\ShipIt\Support;

use Zerofyi\ShipIt\Commands\BasePushCommand;

/**
 * The .env.production setup wizard (two steps).
 *
 * Step 1 — create the file, when the app has no local .env.production yet:
 *   1. Copy the local .env and make it production-ready.
 *   2. Copy .env.example (the user fills in the real values next).
 *   3. Stop — do nothing.
 *
 * Step 2 — verify and fill: scan the created file for the production
 * values a deploy actually needs (database first), pause so the user can
 * edit the file, and re-check until the blockers are gone or the user
 * stops. Values are never displayed — only key names and statuses.
 *
 * The wizard is shared by push:hostinger (first deploy), shipit:doctor
 * (pre-flight check + offer) and shipit:env (instead of a hard error).
 * It never overwrites an existing .env.production without confirmation,
 * and it never prompts in dry-run or non-interactive runs — those return
 * 'deferred' so the caller can fail with a clear message instead.
 */
class EnvProductionWizard
{
    /** Values that are almost certainly placeholders, never real ones. */
    private const PLACEHOLDERS = [
        '', 'null', 'none', 'password', 'secret', 'changeme', 'change-me',
        'change_me', 'example', 'your_password', 'your-password',
        'your_db_password', 'your-db-password', 'laravel', 'root',
    ];

    /** Step-2 re-check rounds before the wizard gives up. */
    private const MAX_RECHECKS = 3;

    /**
     * Run the full wizard. Returns one of:
     *   'ready'    — .env.production exists and passed the step-2 scan
     *                (or already existed before the wizard ran).
     *   'stopped'  — the user chose to stop, or the file could not be made
     *                ready within the re-check rounds.
     *   'deferred' — dry-run or non-interactive: the wizard could not run.
     *                The caller decides what to do (dry-run continues, a
     *                real run fails with a clear message).
     */
    public static function run(BasePushCommand $command, array $site, bool $dryRun = false): string
    {
        $prodPath = base_path('.env.production');

        if (file_exists($prodPath)) {
            return 'ready';
        }

        if ($dryRun) {
            $command->line('<fg=yellow>[!] [DRY RUN] No local .env.production — the wizard would offer to create it (copy .env / copy .env.example / stop).</>');
            return 'deferred';
        }

        if (!$command->isInteractiveRun()) {
            $command->line('<fg=yellow>[!] No local .env.production and no terminal to create one in.</>');
            return 'deferred';
        }

        $created = self::stepOne($command, $site);
        if (!$created) {
            return 'stopped';
        }

        return self::stepTwo($command, $site) ? 'ready' : 'stopped';
    }

    /**
     * Step 1 — create .env.production from one of the two sources, or stop.
     * Also makes sure the new file can never be committed by accident.
     */
    private static function stepOne(BasePushCommand $command, array $site): bool
    {
        $prodPath = base_path('.env.production');
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');
        $siteDir = (string) ($site['site_dir'] ?? '');

        $command->line('');
        $command->line('<fg=cyan>[*] No local .env.production yet — it holds the production values');
        $command->line('    ShipIt uploads to the server as .env on the first deploy.</>');

        $options = [];
        if (file_exists($envPath)) {
            $options[1] = 'Copy your local .env and make it production-ready';
        }
        $options[2] = 'Copy .env.example — you fill in the real values next';
        $options[3] = 'Stop — do nothing';

        foreach ($options as $num => $label) {
            $command->line("   [{$num}] {$label}");
        }
        if (!isset($options[1])) {
            $command->line('    (Option 1 is unavailable — there is no local .env to copy.)');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $answer = $command->ask(' Choose an option (' . implode(',', array_keys($options)) . ') — leave empty to stop');
            if ($answer === null || trim((string) $answer) === '') {
                $command->line('Stopped — .env.production was not created.');
                return false;
            }
            $choice = (int) trim((string) $answer);
            if (isset($options[$choice])) {
                break;
            }
            $command->warn(' Please choose one of: ' . implode(', ', array_keys($options)) . '.');
        }

        if (!isset($choice) || !isset($options[$choice])) {
            $command->line('Stopped — .env.production was not created.');
            return false;
        }

        if ($choice === 3) {
            $command->line('Stopped — .env.production was not created.');
            return false;
        }

        if ($choice === 1) {
            $content = (string) file_get_contents($envPath);
            $result = self::transformContent($content, $siteDir);
            $command->line('    Production-ready values set for: ' . implode(', ', $result['changes']) . '.');
            if ($result['key_generated']) {
                $command->line('    A fresh APP_KEY was generated (the local .env had none).');
            }
            if (!$command->confirmYN(' Write .env.production with these production values?', true)) {
                $command->line('Stopped — .env.production was not created.');
                return false;
            }
            file_put_contents($prodPath, $result['content']);
            $command->line('<fg=green>[+] .env.production created from your local .env.</>');
        } else {
            if (!file_exists($examplePath)) {
                $command->error('[x] No .env.example to copy from either. Create .env.production manually, then run the command again.');
                return false;
            }
            file_put_contents($prodPath, (string) file_get_contents($examplePath));
            $command->line('<fg=green>[+] .env.production created from .env.example.</>');
            $command->line('    Fill in the real production values in the file, then continue below.');
        }

        $guard = self::ensureGitignored(base_path());
        if ($guard === 'appended') {
            $command->line('    Added .env.production to .gitignore so it can never be committed.');
        } elseif ($guard === 'created') {
            $command->line('    Created a .gitignore containing .env.production so it can never be committed.');
        }

        return true;
    }

    /**
     * Step 2 — scan the file for the production values a deploy needs
     * (database first), pause for the user to fill in what is missing,
     * and re-check. Returns true when the file is ready to deploy with.
     */
    private static function stepTwo(BasePushCommand $command, array $site): bool
    {
        $prodPath = base_path('.env.production');
        $siteDir = (string) ($site['site_dir'] ?? '');

        // An APP_KEY is not something the user can meaningfully type in —
        // generate one when the file has none, instead of blocking on it.
        $vars = EnvFile::parse((string) file_get_contents($prodPath));
        if (empty($vars['APP_KEY'])) {
            $content = (string) file_get_contents($prodPath);
            $content = self::setKey($content, 'APP_KEY', self::generateAppKey());
            file_put_contents($prodPath, $content);
            $command->line('    A fresh APP_KEY was generated (the file had none).');
            $vars = EnvFile::parse($content);
        }

        for ($round = 1; $round <= self::MAX_RECHECKS; $round++) {
            $scan = self::scan($vars, $siteDir);

            foreach ($scan['warnings'] as $key => $reason) {
                $command->line("<fg=yellow>    [!] {$key}: {$reason}</>");
            }

            if ($scan['blockers'] === []) {
                $command->line('<fg=green>[+] .env.production is ready for production.</>');
                return true;
            }

            $command->line('');
            $command->line('<fg=yellow>    These values are still missing or placeholders:</>');
            foreach ($scan['blockers'] as $key => $reason) {
                $command->line("      [x] {$key}: {$reason}");
            }
            $command->line('    Edit .env.production to fill them in (database values come from hPanel > Databases).');

            $answer = $command->ask(" Press ENTER to re-check, type 'continue' to deploy anyway, or 'stop' to quit");
            $answer = strtolower(trim((string) $answer));
            if ($answer === 'stop') {
                $command->line('Stopped — .env.production is not ready yet.');
                return false;
            }
            if ($answer === 'continue') {
                $command->line('<fg=yellow>[!] Continuing with missing values — the deploy may fail at the migration step.</>');
                return true;
            }
            $vars = EnvFile::parse((string) file_get_contents($prodPath));
        }

        $command->error('[x] .env.production is still not ready after ' . self::MAX_RECHECKS . ' checks. Fill in the missing values and run the command again.');
        return false;
    }

    /**
     * Turn a local .env into production values, line by line, so comments
     * and ordering survive. Returns the new content, the changed key names
     * (never values) and whether an APP_KEY had to be generated.
     *
     * @return array{content: string, changes: string[], key_generated: bool}
     */
    public static function transformContent(string $content, string $siteDir): array
    {
        $changes = [];
        $keyGenerated = false;
        $hasKey = false;

        $forced = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $siteDir !== '' ? 'https://' . $siteDir : 'https://example.com',
        ];

        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as &$line) {
            if (!preg_match('/^(\s*)(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m)) {
                continue;
            }
            $key = $m[2];
            if ($key === 'APP_KEY') {
                $hasKey = true;
                if (trim($m[3], " \t\"'") === '') {
                    $line = self::setKey($line, 'APP_KEY', self::generateAppKey());
                    $changes[] = 'APP_KEY';
                    $keyGenerated = true;
                }
                continue;
            }
            if (array_key_exists($key, $forced)) {
                $line = self::setKey($line, $key, $forced[$key]);
                $changes[] = $key;
                continue;
            }
            if ($key === 'DEBUGBAR_ENABLED' || $key === 'TELESCOPE_ENABLED') {
                $line = self::setKey($line, $key, 'false');
                $changes[] = $key;
            }
        }
        unset($line);

        if (!$hasKey) {
            $lines[] = 'APP_KEY=' . self::generateAppKey();
            $changes[] = 'APP_KEY';
            $keyGenerated = true;
        }

        return [
            'content' => implode("\n", $lines),
            'changes' => array_values(array_unique($changes)),
            'key_generated' => $keyGenerated,
        ];
    }

    /**
     * Check a parsed .env.production for the values a production deploy
     * needs. Blockers stop the wizard; warnings are advice only.
     * Messages name keys, never values.
     *
     * @param array<string,string> $vars
     * @return array{blockers: array<string,string>, warnings: array<string,string>}
     */
    public static function scan(array $vars, string $siteDir): array
    {
        $blockers = [];
        $warnings = [];

        $connection = strtolower(trim($vars['DB_CONNECTION'] ?? 'mysql'));

        if ($connection === 'sqlite') {
            $warnings['DB_CONNECTION'] = 'SQLite — production will use a local file database, not MySQL.';
        } else {
            if (empty($vars['DB_HOST'])) {
                $warnings['DB_HOST'] = 'not set — Laravel defaults to 127.0.0.1, which is correct on Hostinger.';
            }
            if (self::isPlaceholder($vars['DB_DATABASE'] ?? null)) {
                $blockers['DB_DATABASE'] = 'missing or placeholder — set the database name from hPanel > Databases.';
            }
            if (self::isPlaceholder($vars['DB_USERNAME'] ?? null)) {
                $blockers['DB_USERNAME'] = 'missing or placeholder — set the database username from hPanel > Databases.';
            }
            if (self::isPlaceholder($vars['DB_PASSWORD'] ?? null)) {
                $blockers['DB_PASSWORD'] = 'missing or placeholder — set the database password from hPanel > Databases.';
            }
        }

        if (empty($vars['APP_KEY']) || !str_starts_with(trim($vars['APP_KEY']), 'base64:')) {
            $blockers['APP_KEY'] = 'missing — ShipIt will generate one.';
        }

        if (isset($vars['APP_ENV']) && strtolower(trim($vars['APP_ENV'])) !== 'production') {
            $warnings['APP_ENV'] = 'is not production — set APP_ENV=production.';
        }
        if (strtolower(trim($vars['APP_DEBUG'] ?? '')) === 'true' || trim($vars['APP_DEBUG'] ?? '') === '1') {
            $warnings['APP_DEBUG'] = 'debug mode is on in production — set APP_DEBUG=false.';
        }

        $appUrl = trim($vars['APP_URL'] ?? '');
        if ($appUrl === '') {
            $warnings['APP_URL'] = 'not set — set it to https://' . $siteDir . '.';
        } elseif (str_starts_with($appUrl, 'http://localhost') || str_starts_with($appUrl, 'http://127.0.0.1')) {
            $warnings['APP_URL'] = 'points at localhost — set it to https://' . $siteDir . '.';
        } elseif ($siteDir !== '' && $appUrl !== 'https://' . $siteDir) {
            $warnings['APP_URL'] = 'does not match https://' . $siteDir . ' — update it if this is the public URL.';
        }

        if (strtolower(trim($vars['MAIL_MAILER'] ?? '')) === 'log') {
            $warnings['MAIL_MAILER'] = 'mail goes to the log file in production.';
        }
        if (strtolower(trim($vars['LOG_LEVEL'] ?? '')) === 'debug') {
            $warnings['LOG_LEVEL'] = 'debug logging in production is noisy — consider warning or error.';
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /** Is this value empty or one of the well-known placeholder literals? */
    public static function isPlaceholder(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        return in_array(strtolower(trim($value)), self::PLACEHOLDERS, true);
    }

    /**
     * Make sure .env.production can never be committed: append it to
     * .gitignore when missing (creating the file when there is none).
     * Returns 'already', 'appended' or 'created'.
     */
    public static function ensureGitignored(string $basePath): string
    {
        $path = rtrim($basePath, '/') . '/.gitignore';
        if (!file_exists($path)) {
            file_put_contents($path, "# Production secrets — never commit\n.env.production\n");
            return 'created';
        }

        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '.env.production' || $line === '.env*') {
                return 'already';
            }
        }

        file_put_contents($path, (string) file_get_contents($path) . "\n# Production secrets — never commit\n.env.production\n");
        return 'appended';
    }

    /** A fresh Laravel application key, exactly as key:generate makes one. */
    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Replace (or append) one KEY=value line, preserving any leading
     * whitespace and `export ` prefix on the replaced line.
     */
    private static function setKey(string $content, string $key, string $value): string
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as &$line) {
            if (preg_match('/^(\s*)(?:export\s+)?' . preg_quote($key, '/') . '\s*=.*$/', $line, $m)) {
                $prefix = $m[1];
                $export = str_contains($line, 'export ') ? 'export ' : '';
                $line = $prefix . $export . $key . '=' . $value;
                unset($line);
                return implode("\n", $lines);
            }
        }
        unset($line);

        $lines[] = $key . '=' . $value;
        return implode("\n", $lines);
    }
}
