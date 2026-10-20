<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the server-side helper (resources/server/db-tool.php) for
 * real: builds a SQLite database with hostile content (semicolons,
 * quotes, newlines, unicode, NULLs, binary blobs), dumps it with
 * shipit_dump(), restores into a fresh database with shipit_restore()
 * and asserts a byte-exact roundtrip. This is the same code path that
 * runs on Hostinger when mysqldump is unavailable.
 */
class DbToolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../resources/server/db-tool.php';
        $this->dir = sys_get_temp_dir() . '/shipit-dbtool-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_parse_env_matches_local_parser_semantics(): void
    {
        $path = $this->dir . '/.env';
        file_put_contents($path, implode("\n", [
            'DB_CONNECTION=sqlite',
            'DB_DATABASE="/some path/db.sqlite"',
            "DB_PASSWORD='p@ss #word'",
            'PLAIN=simple # comment',
        ]));
        $env = shipit_parse_env($path);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame('/some path/db.sqlite', $env['DB_DATABASE']);
        $this->assertSame('p@ss #word', $env['DB_PASSWORD']);
        $this->assertSame('simple', $env['PLAIN']);
    }

    public function test_statement_extractor_respects_quotes_and_comments(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b');\n"
            . "INSERT INTO t VALUES ('it''s; ok'); -- a comment ; here\n"
            . "INSERT INTO t VALUES ('esc\\'; still string');\n"
            . "/* block ; comment */ INSERT INTO t VALUES (\"d;q\");\n"
            . 'SELECT `weird;col` FROM t;';

        [$statements, $remainder] = shipit_extract_statements($sql, true);

        $this->assertSame('', $remainder);
        $this->assertCount(5, $statements);
        $this->assertStringContainsString("'a;b'", $statements[0]);
        $this->assertStringContainsString("'it''s; ok'", $statements[1]);
        $this->assertStringContainsString("esc\\'; still string", $statements[2]);
        $this->assertStringContainsString('"d;q"', $statements[3]);
        $this->assertStringContainsString('`weird;col`', $statements[4]);
    }

    public function test_statement_extractor_streams_partial_buffers(): void
    {
        $part1 = "INSERT INTO t VALUES ('cut off in the mid";
        [$none, $rest] = shipit_extract_statements($part1, false);
        $this->assertSame([], $none);
        $this->assertSame($part1, $rest);

        [$done, $rest2] = shipit_extract_statements($rest . "dle'); SELECT 2;", true);
        $this->assertCount(2, $done);
        $this->assertSame('', $rest2);
    }

    public function test_sql_literal_formatting(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->assertSame('NULL', shipit_sql_literal($pdo, null));
        $this->assertSame('42', shipit_sql_literal($pdo, 42));
        $this->assertSame("'o''clock'", shipit_sql_literal($pdo, "o'clock"));
        // SQLite dialect uses X'..' blob literals (MySQL would get 0x..).
        $this->assertSame("X'00ff'", shipit_sql_literal($pdo, "\x00\xff"));
    }

    public function test_sqlite_dump_restore_roundtrip_is_byte_exact(): void
    {
        $sitePath = $this->dir;
        file_put_contents($sitePath . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=database.sqlite\n");

        $env = shipit_parse_env($sitePath . '/.env');
        $pdo = shipit_pdo($env, $sitePath);
        $this->assertNotNull($pdo);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, note TEXT, payload BLOB, score REAL)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, body TEXT)');
        $pdo->exec('CREATE VIEW user_names AS SELECT id, name FROM users');

        $insert = $pdo->prepare('INSERT INTO users (id, name, note, payload, score) VALUES (?, ?, ?, ?, ?)');
        $rows = [
            [1, 'Nahid', 'plain note', 'text-blob', 9.5],
            [2, "o'clock; DROP TABLE users; --", "multi\nline\nnote", "\x00\x01\x02binary\xff", null],
            [3, 'Emoji 🚀 ünïcode', 'semi;colon inside', '', 0.0],
            [4, '', null, null, -3.25],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $pdo->exec("INSERT INTO posts (id, user_id, body) VALUES (1, 1, 'hello; world')");

        $dumpPath = $this->dir . '/dump.sql.gz';
        $stats = shipit_dump($pdo, 'sqlite', $dumpPath);
        $this->assertSame(2, $stats['tables']);
        $this->assertSame(5, $stats['rows']);
        $this->assertFileExists($dumpPath);
        $pdo = null;

        // Restore into a brand-new database file.
        $site2 = $this->dir . '/site2';
        mkdir($site2);
        file_put_contents($site2 . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=restored.sqlite\n");
        $env2 = shipit_parse_env($site2 . '/.env');
        $pdo2 = shipit_pdo($env2, $site2);
        $restore = shipit_restore($pdo2, 'sqlite', $dumpPath);
        $this->assertGreaterThan(5, $restore['statements']);

        $restored = $pdo2->query('SELECT * FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(4, $restored);
        $this->assertSame("o'clock; DROP TABLE users; --", $restored[1]['name']);
        $this->assertSame("multi\nline\nnote", $restored[1]['note']);
        $this->assertSame("\x00\x01\x02binary\xff", $restored[1]['payload']);
        $this->assertNull($restored[1]['score']);
        $this->assertSame('Emoji 🚀 ünïcode', $restored[2]['name']);
        $this->assertNull($restored[3]['note']);

        $post = $pdo2->query('SELECT body FROM posts WHERE id = 1')->fetchColumn();
        $this->assertSame('hello; world', $post);

        // The view survived too.
        $viewCount = $pdo2->query('SELECT COUNT(*) FROM user_names')->fetchColumn();
        $this->assertEquals(4, $viewCount);
    }

    public function test_restore_replaces_existing_data_exactly(): void
    {
        $sitePath = $this->dir;
        file_put_contents($sitePath . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=db.sqlite\n");
        $env = shipit_parse_env($sitePath . '/.env');

        $pdo = shipit_pdo($env, $sitePath);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t VALUES (1, 'snapshot-state')");
        $dumpPath = $this->dir . '/snap.sql.gz';
        shipit_dump($pdo, 'sqlite', $dumpPath);

        // Life moves on after the snapshot...
        $pdo->exec("INSERT INTO t VALUES (2, 'written-after-snapshot')");
        $pdo->exec('CREATE TABLE extra (id INTEGER)');
        $this->assertEquals(2, $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());

        shipit_restore($pdo, 'sqlite', $dumpPath);

        // ...and a restore returns the database to the snapshot exactly:
        // post-snapshot rows are gone and so is the extra table.
        $this->assertEquals(1, $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        $this->assertSame('snapshot-state', $pdo->query('SELECT v FROM t WHERE id = 1')->fetchColumn());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='extra'")->fetchAll();
        $this->assertSame([], $tables);
    }
}
