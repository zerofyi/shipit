<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\BackupPlan;

class BackupPlanTest extends TestCase
{
    public function test_snapshot_ids_are_chronologically_sortable(): void
    {
        $a = BackupPlan::snapshotId(new \DateTimeImmutable('2026-10-20 09:15:30'));
        $b = BackupPlan::snapshotId(new \DateTimeImmutable('2026-10-20 16:45:00'));
        $this->assertSame('20261020-091530', $a);
        $this->assertLessThan(0, strcmp($a, $b));
        $this->assertTrue(BackupPlan::isValidSnapshotId($a));
        $this->assertFalse(BackupPlan::isValidSnapshotId('../etc/passwd'));
        $this->assertFalse(BackupPlan::isValidSnapshotId('20261020'));
        $this->assertFalse(BackupPlan::isValidSnapshotId(''));
    }

    public function test_prune_keeps_newest_and_protects(): void
    {
        $ids = ['20261018-090000', '20261019-090000', '20261020-090000', '20261020-120000', '20261020-150000'];

        $this->assertSame(['20261018-090000', '20261019-090000'], BackupPlan::pruneList($ids, 3));
        $this->assertSame([], BackupPlan::pruneList($ids, 5));
        $this->assertSame([], BackupPlan::pruneList([], 3));

        // The just-created snapshot is never pruned, even if clocks misbehave.
        $delete = BackupPlan::pruneList($ids, 2, '20261018-090000');
        $this->assertNotContains('20261018-090000', $delete);
        $this->assertCount(2, $delete);

        // Garbage ids are ignored entirely.
        $this->assertSame([], BackupPlan::pruneList(['junk', '../../x'], 3));
    }

    public function test_parse_meta(): void
    {
        $meta = BackupPlan::parseMeta("commit=abc123\ndb_method=mysqldump\nsize_db=1024\nweird line\nhas_env=1\n");
        $this->assertSame('abc123', $meta['commit']);
        $this->assertSame('mysqldump', $meta['db_method']);
        $this->assertSame('1024', $meta['size_db']);
        $this->assertArrayNotHasKey('weird line', $meta);
    }

    public function test_parse_snapshot_listing_newest_first(): void
    {
        $output = implode("\n", [
            '===SNAP 20261019-090000',
            '{"id":"20261019-090000","commit":"aaa"}',
            '===SNAP 20261020-090000',
            '{"id":"20261020-090000","commit":"bbb"}',
            '===SNAP 20261018-090000',
            '',
        ]);
        $snapshots = BackupPlan::parseSnapshotListing($output);
        $this->assertCount(3, $snapshots);
        $this->assertSame('20261020-090000', $snapshots[0]['id']);
        $this->assertSame('bbb', $snapshots[0]['manifest']['commit']);
        $this->assertNull($snapshots[2]['manifest']);
    }

    public function test_human_size(): void
    {
        $this->assertSame('0 B', BackupPlan::humanSize(0));
        $this->assertSame('1 KB', BackupPlan::humanSize(1024));
        $this->assertSame('1.5 MB', BackupPlan::humanSize(1024 * 1024 + 512 * 1024));
    }
}
