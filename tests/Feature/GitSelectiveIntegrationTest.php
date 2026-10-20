<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Illuminate\Support\Facades\Process;
use Zerofyi\ShipIt\Support\GitChanges;
use Zerofyi\ShipIt\Tests\TestCase;

/**
 * Real-git integration test for the selective deploy mechanic.
 *
 * No fakes: a real git binary, a real bare "GitHub" remote and a real
 * working repository in a temp directory. It executes exactly the
 * staging/commit/push commands PushGithub builds, and proves the core
 * promise of --only / --files: the selected file lands on the remote,
 * and every other work-in-progress change stays local and uncommitted.
 */
class GitSelectiveIntegrationTest extends TestCase
{
    private string $dir;
    private string $work;
    private string $remote;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Process::run('which git')->successful()) {
            $this->markTestSkipped('git binary not available.');
        }

        $this->dir = sys_get_temp_dir() . '/shipit-git-' . uniqid();
        $this->work = $this->dir . '/work';
        $this->remote = $this->dir . '/remote.git';
        mkdir($this->work, 0777, true);

        $this->git($this->dir, 'git init --bare remote.git');
        $this->git($this->work, 'git init -b main');
        $this->git($this->work, 'git config user.email test@shipit.local');
        $this->git($this->work, 'git config user.name "ShipIt Test"');
        $this->git($this->work, 'git remote add origin ' . escapeshellarg($this->remote));

        file_put_contents($this->work . '/hotfix.php', "<?php // v1\n");
        file_put_contents($this->work . '/wip.php', "<?php // wip v1\n");
        file_put_contents($this->work . '/stable.php', "<?php // stable\n");
        $this->git($this->work, 'git add .');
        $this->git($this->work, 'git commit -m initial');
        $this->git($this->work, 'git push -u origin main');
    }

    protected function tearDown(): void
    {
        Process::run('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function git(string $cwd, string $command): string
    {
        $result = Process::path($cwd)->run($command);
        $this->assertTrue($result->successful(), "git command failed: {$command}\n" . $result->errorOutput());
        // Right-trim ONLY: porcelain output is fixed-column and its first
        // line may start with a meaningful space.
        return rtrim($result->output(), "\r\n");
    }

    public function test_selective_commit_pushes_only_the_chosen_file(): void
    {
        // The user's scenario: several files changed, only one is ready.
        file_put_contents($this->work . '/hotfix.php', "<?php // v2 urgent fix\n");
        file_put_contents($this->work . '/wip.php', "<?php // wip v2 NOT finished\n");
        file_put_contents($this->work . '/brand-new.php', "<?php // also not ready\n");

        $porcelain = $this->git($this->work, 'git status --porcelain');
        $entries = GitChanges::parsePorcelain($porcelain);
        $paths = array_map(fn ($e) => $e['path'], $entries);
        $this->assertEqualsCanonicalizing(['hotfix.php', 'wip.php', 'brand-new.php'], $paths);

        // Exactly the staging command PushGithub builds for a selection.
        $selected = array_values(array_filter($entries, fn ($e) => $e['path'] === 'hotfix.php'));
        $addCmd = 'git add --';
        foreach ($selected as $entry) {
            $addCmd .= ' ' . escapeshellarg($entry['path']);
        }
        $this->git($this->work, $addCmd);
        $this->git($this->work, 'git commit -m "hotfix only"');
        $this->git($this->work, 'git push origin main');

        // The remote received the hotfix — and nothing else.
        $remoteFiles = $this->git($this->dir, 'git --git-dir=remote.git show --name-only --pretty=format: main');
        $this->assertSame(['hotfix.php'], array_values(array_filter(explode("\n", $remoteFiles))));
        $remoteContent = $this->git($this->dir, 'git --git-dir=remote.git show main:hotfix.php');
        $this->assertStringContainsString('v2 urgent fix', $remoteContent);

        // Work in progress is untouched: still modified/untracked locally.
        $after = GitChanges::parsePorcelain($this->git($this->work, 'git status --porcelain'));
        $afterPaths = array_map(fn ($e) => $e['path'], $after);
        $this->assertEqualsCanonicalizing(['wip.php', 'brand-new.php'], $afterPaths);
        $this->assertStringContainsString('wip v2 NOT finished', file_get_contents($this->work . '/wip.php'));
    }

    public function test_rename_selection_stages_both_sides(): void
    {
        $this->git($this->work, 'git mv stable.php renamed.php');
        file_put_contents($this->work . '/wip.php', "<?php // wip v2\n");

        $entries = GitChanges::parsePorcelain($this->git($this->work, 'git status --porcelain'));
        $rename = array_values(array_filter($entries, fn ($e) => $e['label'] === 'Renamed'));
        $this->assertCount(1, $rename);
        $this->assertSame('stable.php', $rename[0]['orig_path']);
        $this->assertSame('renamed.php', $rename[0]['path']);

        // Mirrors PushGithub's staging: renames detected by git are
        // already staged, so only the new path is (re-)added.
        $addCmd = 'git add -- ' . escapeshellarg($rename[0]['path']);
        $this->git($this->work, $addCmd);
        $this->git($this->work, 'git commit -m "rename only"');
        $this->git($this->work, 'git push origin main');

        $remoteFiles = $this->git($this->dir, 'git --git-dir=remote.git show --name-status --pretty=format: main');
        $this->assertStringContainsString('renamed.php', $remoteFiles);
        $this->assertStringNotContainsString('wip.php', $remoteFiles);
    }
}
