<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\GitChanges;

class GitChangesTest extends TestCase
{
    public function test_parses_porcelain_output(): void
    {
        $output = implode("\n", [
            ' M app/Http/Controllers/HomeController.php',
            '?? app/NewFile.php',
            'A  app/Added.php',
            ' D app/Deleted.php',
            'R  app/Old.php -> app/New.php',
            '',
        ]);

        $entries = GitChanges::parsePorcelain($output);

        $this->assertCount(5, $entries);
        $this->assertSame('app/Http/Controllers/HomeController.php', $entries[0]['path']);
        $this->assertSame('Modified', $entries[0]['label']);
        $this->assertSame('Untracked (new file)', $entries[1]['label']);
        $this->assertSame('Added', $entries[2]['label']);
        $this->assertSame('Deleted', $entries[3]['label']);
        $this->assertSame('app/New.php', $entries[4]['path']);
        $this->assertSame('app/Old.php', $entries[4]['orig_path']);
        $this->assertSame('Renamed', $entries[4]['label']);
    }

    public function test_unquotes_special_paths(): void
    {
        $entries = GitChanges::parsePorcelain(' M "app/My File.php"');
        $this->assertSame('app/My File.php', $entries[0]['path']);
    }

    public function test_conflicted_files_are_not_selectable(): void
    {
        $entries = GitChanges::parsePorcelain("UU app/Conflict.php\n M app/Ok.php");
        $this->assertFalse(GitChanges::isSelectable($entries[0]));
        $this->assertTrue(GitChanges::isSelectable($entries[1]));
    }

    public function test_asset_build_detection(): void
    {
        $this->assertTrue(GitChanges::requiresAssetBuild('resources/js/app.js'));
        $this->assertTrue(GitChanges::requiresAssetBuild('resources/views/home.blade.php'));
        $this->assertTrue(GitChanges::requiresAssetBuild('vite.config.js'));
        $this->assertTrue(GitChanges::requiresAssetBuild('package.json'));
        $this->assertFalse(GitChanges::requiresAssetBuild('app/Models/User.php'));
        $this->assertFalse(GitChanges::requiresAssetBuild('routes/web.php'));
    }

    public function test_parse_selection_forms(): void
    {
        $this->assertSame([0, 2, 4], GitChanges::parseSelection('1,3,5', 5)['indices']);
        $this->assertSame([0, 1, 2, 3, 4], GitChanges::parseSelection('all', 5)['indices']);
        $this->assertSame([1, 2, 3], GitChanges::parseSelection('2-4', 5)['indices']);
        $this->assertSame([0, 2, 3, 4], GitChanges::parseSelection('1, 3-5', 5)['indices']);
        $this->assertSame([0], GitChanges::parseSelection('1,1,1', 5)['indices']);
    }

    public function test_parse_selection_errors(): void
    {
        $this->assertNotNull(GitChanges::parseSelection('', 5)['error']);
        $this->assertNotNull(GitChanges::parseSelection('9', 5)['error']);
        $this->assertNotNull(GitChanges::parseSelection('0', 5)['error']);
        $this->assertNotNull(GitChanges::parseSelection('abc', 5)['error']);
        $this->assertNotNull(GitChanges::parseSelection('4-2', 5)['error']);
    }
}
