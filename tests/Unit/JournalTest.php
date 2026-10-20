<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\Journal;

class JournalTest extends TestCase
{
    public function test_decode_tolerates_garbage(): void
    {
        $this->assertSame(['version' => 1, 'entries' => []], Journal::decode(null));
        $this->assertSame(['version' => 1, 'entries' => []], Journal::decode(''));
        $this->assertSame(['version' => 1, 'entries' => []], Journal::decode('not json'));
        $this->assertSame(['version' => 1, 'entries' => []], Journal::decode('{"nope":true}'));
    }

    public function test_encode_decode_roundtrip(): void
    {
        $journal = Journal::append(Journal::empty(), [
            'type' => 'deploy', 'status' => 'success', 'at' => '2026-10-20 10:00:00',
            'branch' => 'main', 'to_commit' => 'abc123', 'backup_id' => '20261020-100000',
        ]);
        $again = Journal::decode(Journal::encode($journal));
        $this->assertSame($journal, $again);
    }

    public function test_append_assigns_sequential_ids_and_defaults(): void
    {
        $journal = Journal::append(Journal::empty(), ['type' => 'deploy']);
        $journal = Journal::append($journal, ['type' => 'backup']);
        $this->assertSame(1, $journal['entries'][0]['id']);
        $this->assertSame(2, $journal['entries'][1]['id']);
        $this->assertSame('started', $journal['entries'][0]['status']);
        $this->assertFalse($journal['entries'][0]['backup_skipped']);
    }

    public function test_update_last_patches_latest_entry_only(): void
    {
        $journal = Journal::append(Journal::empty(), ['type' => 'deploy', 'status' => 'started']);
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'started']);
        $journal = Journal::updateLast($journal, ['status' => 'success']);
        $this->assertSame('started', $journal['entries'][0]['status']);
        $this->assertSame('success', $journal['entries'][1]['status']);
    }

    public function test_last_successful_deploy_and_current_commit(): void
    {
        $journal = Journal::empty();
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'success', 'to_commit' => 'aaa']);
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'failed', 'to_commit' => 'bbb']);
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'success', 'to_commit' => 'ccc']);

        $this->assertSame('ccc', Journal::lastSuccessfulDeploy($journal)['to_commit']);
        $this->assertSame('ccc', Journal::currentKnownCommit($journal));

        $journal = Journal::append($journal, ['type' => 'rollback', 'status' => 'success', 'to_commit' => 'aaa']);
        $this->assertSame('aaa', Journal::currentKnownCommit($journal));
    }

    public function test_restore_points_newest_first_and_only_with_backups(): void
    {
        $journal = Journal::empty();
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'success', 'at' => '2026-10-19 09:00:00', 'to_commit' => 'aaa', 'backup_id' => '20261019-090000']);
        $journal = Journal::append($journal, ['type' => 'deploy', 'status' => 'success', 'at' => '2026-10-20 09:00:00', 'to_commit' => 'bbb', 'backup_id' => null, 'backup_skipped' => true]);
        $journal = Journal::append($journal, ['type' => 'backup', 'status' => 'success', 'at' => '2026-10-20 12:00:00', 'to_commit' => 'bbb', 'backup_id' => '20261020-120000']);

        $points = Journal::restorePoints($journal);
        $this->assertCount(2, $points);
        $this->assertSame('20261020-120000', $points[0]['backup_id']);
        $this->assertSame('20261019-090000', $points[1]['backup_id']);
    }
}
