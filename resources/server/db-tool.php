<?php
/**
 * ShipIt server-side database helper.
 *
 * This file is uploaded to the Hostinger server at deploy/backup time and
 * executed there with the server's PHP. It is NEVER part of the host
 * Laravel application. Subcommands:
 *
 *   php db-tool.php info     <sitePath>
 *       Prints JSON: {"connection":"mysql|sqlite|none","database":"...",
 *       "tables":N} for the site configured in <sitePath>/.env
 *
 *   php db-tool.php dump     <sitePath> <output.sql.gz>
 *       Full database dump (PDO based) written as gzipped SQL. Used as
 *       the fallback when mysqldump is unavailable on the server.
 *
 *   php db-tool.php defaults <sitePath> <outputFile>
 *       Writes a MySQL option file (0600) with host/user/password so the
 *       mysqldump fast path never exposes credentials on a command line.
 *
 *   php db-tool.php restore  <sitePath> <input.sql.gz>
 *       Drops every table/view in the database, then replays the dump.
 *
 * All credentials are read from the site's own .env ON THE SERVER. They
 * never travel over the wire and are never printed.
 *
 * The pure functions in this file (env parsing, SQL literal formatting,
 * statement extraction) are exercised by ShipIt's test suite.
 */

function shipit_parse_env(string $path): array
{
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
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
                // phpdotenv parity: only \n and \r are expanded.
                $val = str_replace(['\\n', '\\r'], ["\n", "\r"], $val);
            }
        } else {
            $hash = strpos($val, ' #');
            if ($hash !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
        }
        $vars[$key] = $val;
    }
    return $vars;
}

function shipit_pdo(array $env, string $sitePath): ?PDO
{
    $connection = $env['DB_CONNECTION'] ?? 'mysql';

    if ($connection === 'sqlite') {
        $database = $env['DB_DATABASE'] ?? 'database/database.sqlite';
        if ($database !== ':memory:' && !str_starts_with($database, '/')) {
            $database = rtrim($sitePath, '/') . '/' . ltrim($database, '/');
        }
        return new PDO('sqlite:' . $database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    if ($connection === 'mysql') {
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $database = $env['DB_DATABASE'] ?? '';
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    return null;
}

function shipit_sql_literal(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    $value = (string) $value;
    // Binary-safe hex literal when the payload is not clean UTF-8 text.
    // Dialects differ: MySQL accepts 0x.., SQLite wants X'..'.
    if (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
        $hex = bin2hex($value);
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "X'{$hex}'"
            : '0x' . $hex;
    }
    return $pdo->quote($value);
}

/**
 * Extract complete SQL statements from a buffer.
 *
 * Returns [statements, remainder]. The scanner is quote-aware: semicolons
 * inside 'single', "double" or `backtick` quoted regions, -- and # line
 * comments and /* block comments never terminate a statement. Backslash
 * escapes are honoured inside single/double quotes (MySQL style).
 *
 * @return array{0: array<int,string>, 1: string}
 */
function shipit_extract_statements(string $buffer, bool $final = false): array
{
    $statements = [];
    $len = strlen($buffer);
    $start = 0;
    $i = 0;
    $inSingle = $inDouble = $inBacktick = $inLine = $inBlock = false;

    while ($i < $len) {
        $c = $buffer[$i];
        $n = $i + 1 < $len ? $buffer[$i + 1] : '';

        if ($inLine) {
            if ($c === "\n") {
                $inLine = false;
            }
            $i++;
            continue;
        }
        if ($inBlock) {
            if ($c === '*' && $n === '/') {
                $inBlock = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }
        if ($inSingle) {
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === "'") {
                if ($n === "'") {
                    $i += 2;
                    continue;
                }
                $inSingle = false;
            }
            $i++;
            continue;
        }
        if ($inDouble) {
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === '"') {
                $inDouble = false;
            }
            $i++;
            continue;
        }
        if ($inBacktick) {
            if ($c === '`') {
                $inBacktick = false;
            }
            $i++;
            continue;
        }

        // Outside any quote/comment.
        if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($buffer[$i + 2]))) {
            $inLine = true;
            $i++;
            continue;
        }
        if ($c === '#') {
            $inLine = true;
            $i++;
            continue;
        }
        if ($c === '/' && $n === '*') {
            $inBlock = true;
            $i += 2;
            continue;
        }
        if ($c === "'") {
            $inSingle = true;
            $i++;
            continue;
        }
        if ($c === '"') {
            $inDouble = true;
            $i++;
            continue;
        }
        if ($c === '`') {
            $inBacktick = true;
            $i++;
            continue;
        }
        if ($c === ';') {
            $statement = trim(substr($buffer, $start, $i - $start + 1));
            if ($statement !== '' && $statement !== ';') {
                $statements[] = $statement;
            }
            $i++;
            $start = $i;
            continue;
        }
        $i++;
    }

    $remainder = substr($buffer, $start);
    if ($final && trim($remainder) !== '') {
        $statements[] = trim($remainder);
        $remainder = '';
    }

    return [$statements, $remainder];
}

/** @return array<int, array{name:string, sql:?string, type:string}> */
function shipit_schema_objects(PDO $pdo, string $driver): array
{
    $objects = [];
    if ($driver === 'sqlite') {
        $rows = $pdo->query("SELECT name, sql, type FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND type IN ('table','view') ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $objects[] = ['name' => $row['name'], 'sql' => $row['sql'], 'type' => $row['type']];
        }
        return $objects;
    }

    // MySQL / MariaDB
    $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $row) {
        $name = $row[0];
        $type = (isset($row[1]) && stripos((string) $row[1], 'VIEW') !== false) ? 'view' : 'table';
        $create = $pdo->query('SHOW CREATE ' . ($type === 'view' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $name) . '`')->fetch(PDO::FETCH_NUM);
        $sql = $create[1] ?? null;
        $objects[] = ['name' => $name, 'sql' => $sql ? (string) $sql : null, 'type' => $type];
    }
    return $objects;
}

function shipit_dump(PDO $pdo, string $driver, string $outPath): array
{
    $objects = shipit_schema_objects($pdo, $driver);
    $gz = gzopen($outPath, 'wb9');
    if ($gz === false) {
        throw new RuntimeException("Cannot open dump target: {$outPath}");
    }

    $tables = 0;
    $rowsTotal = 0;

    gzwrite($gz, "-- ShipIt database snapshot\n-- Generated: " . date('Y-m-d H:i:s') . "\n");
    if ($driver === 'mysql') {
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    } else {
        gzwrite($gz, "PRAGMA foreign_keys=OFF;\n");
    }

    // Tables first, then views (views may depend on tables).
    $ordered = array_merge(
        array_filter($objects, fn ($o) => $o['type'] === 'table'),
        array_filter($objects, fn ($o) => $o['type'] === 'view')
    );

    foreach ($ordered as $object) {
        $name = $object['name'];
        $quoted = '`' . str_replace('`', '``', $name) . '`';
        $dropKind = $object['type'] === 'view' ? 'VIEW' : 'TABLE';
        gzwrite($gz, "\nDROP {$dropKind} IF EXISTS {$quoted};\n");
        if ($object['sql']) {
            gzwrite($gz, $object['sql'] . ";\n");
        }

        if ($object['type'] !== 'table') {
            continue;
        }
        $tables++;

        $stmt = $pdo->query("SELECT * FROM {$quoted}");
        $columns = null;
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = '`' . implode('`,`', array_map(fn ($c) => str_replace('`', '``', $c), array_keys($row))) . '`';
            }
            $values = [];
            foreach ($row as $value) {
                $values[] = shipit_sql_literal($pdo, $value);
            }
            $batch[] = '(' . implode(',', $values) . ')';
            $rowsTotal++;
            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== [] && $columns !== null) {
            gzwrite($gz, "INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    if ($driver === 'mysql') {
        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    } else {
        gzwrite($gz, "\nPRAGMA foreign_keys=ON;\n");
    }

    gzclose($gz);
    return ['tables' => $tables, 'rows' => $rowsTotal];
}

function shipit_restore(PDO $pdo, string $driver, string $inPath): array
{
    if (!is_file($inPath)) {
        throw new RuntimeException("Dump file not found: {$inPath}");
    }

    // 1. Drop every existing object so the restore is exact, not a merge.
    $objects = shipit_schema_objects($pdo, $driver);
    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    } else {
        $pdo->exec('PRAGMA foreign_keys=OFF');
    }
    foreach (array_reverse($objects) as $object) {
        $quoted = '`' . str_replace('`', '``', $object['name']) . '`';
        $kind = $object['type'] === 'view' ? 'VIEW' : 'TABLE';
        $pdo->exec("DROP {$kind} IF EXISTS {$quoted}");
    }
    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // 2. Replay the dump, streaming so large files stay memory-safe.
    $gz = gzopen($inPath, 'rb');
    if ($gz === false) {
        throw new RuntimeException("Cannot open dump file: {$inPath}");
    }

    $buffer = '';
    $executed = 0;
    while (!gzeof($gz)) {
        $chunk = gzread($gz, 65536);
        if ($chunk === false) {
            break;
        }
        $buffer .= $chunk;
        [$statements, $buffer] = shipit_extract_statements($buffer, false);
        foreach ($statements as $statement) {
            // Pure comment blocks carry no executable SQL.
            $stripped = preg_replace('#^\s*(--[^\n]*(\n|$)|/\*.*?\*/)#s', '', $statement);
            if ($stripped === null || trim($stripped) === '') {
                continue;
            }
            $pdo->exec($statement);
            $executed++;
        }
    }
    [$tail, $buffer] = shipit_extract_statements($buffer, true);
    foreach ($tail as $statement) {
        $stripped = preg_replace('#^\s*(--[^\n]*(\n|$)|/\*.*?\*/)#s', '', $statement);
        if ($stripped === null || trim($stripped) === '') {
            continue;
        }
        $pdo->exec($statement);
        $executed++;
    }
    gzclose($gz);

    return ['statements' => $executed];
}

function shipit_main(array $argv): int
{
    $command = $argv[1] ?? '';
    $sitePath = rtrim($argv[2] ?? '', '/');
    $target = $argv[3] ?? '';

    if ($sitePath === '' || !is_dir($sitePath)) {
        fwrite(STDERR, "Site path not found.\n");
        return 1;
    }

    $env = shipit_parse_env($sitePath . '/.env');
    $connection = $env['DB_CONNECTION'] ?? 'mysql';

    try {
        switch ($command) {
            case 'info':
                if (!is_file($sitePath . '/.env')) {
                    echo json_encode(['connection' => 'none', 'database' => null, 'tables' => 0]) . "\n";
                    return 0;
                }
                $pdo = shipit_pdo($env, $sitePath);
                if ($pdo === null) {
                    echo json_encode(['connection' => $connection, 'database' => $env['DB_DATABASE'] ?? null, 'tables' => 0, 'supported' => false]) . "\n";
                    return 0;
                }
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $objects = shipit_schema_objects($pdo, $driver);
                $tables = count(array_filter($objects, fn ($o) => $o['type'] === 'table'));
                echo json_encode(['connection' => $connection, 'database' => $env['DB_DATABASE'] ?? null, 'tables' => $tables, 'supported' => true]) . "\n";
                return 0;

            case 'defaults':
                if ($target === '') {
                    fwrite(STDERR, "Target file required.\n");
                    return 1;
                }
                $content = "[client]\n"
                    . 'host=' . ($env['DB_HOST'] ?? '127.0.0.1') . "\n"
                    . 'port=' . ($env['DB_PORT'] ?? '3306') . "\n"
                    . 'user=' . ($env['DB_USERNAME'] ?? '') . "\n"
                    . 'password=' . ($env['DB_PASSWORD'] ?? '') . "\n";
                file_put_contents($target, $content);
                chmod($target, 0600);
                echo "OK\n";
                return 0;

            case 'dump':
                if ($target === '') {
                    fwrite(STDERR, "Output file required.\n");
                    return 1;
                }
                $pdo = shipit_pdo($env, $sitePath);
                if ($pdo === null) {
                    fwrite(STDERR, "Unsupported DB_CONNECTION: {$connection}\n");
                    return 1;
                }
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $stats = shipit_dump($pdo, $driver, $target);
                echo json_encode($stats) . "\n";
                return 0;

            case 'restore':
                if ($target === '') {
                    fwrite(STDERR, "Input file required.\n");
                    return 1;
                }
                $pdo = shipit_pdo($env, $sitePath);
                if ($pdo === null) {
                    fwrite(STDERR, "Unsupported DB_CONNECTION: {$connection}\n");
                    return 1;
                }
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $stats = shipit_restore($pdo, $driver, $target);
                echo json_encode($stats) . "\n";
                return 0;

            default:
                fwrite(STDERR, "Unknown subcommand: {$command}\n");
                return 1;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        return 1;
    }
}

// Run only when executed directly (the file is includable for tests).
if (PHP_SAPI === 'cli' && isset($argv) && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    exit(shipit_main($argv));
}
