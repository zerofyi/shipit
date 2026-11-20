<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Zerofyi\ShipIt\Tests\Fakes\FakeServer;
use Zerofyi\ShipIt\Tests\TestCase;

class PushGithubTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidEnv();
        $this->server = new FakeServer();
        $this->server->register();
    }

    public function test_full_deploy_commits_everything_and_pushes(): void
    {
        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranContaining('git add .'));
        $this->assertTrue($this->server->ranContaining('git push -u origin'));
        $this->assertFalse($this->server->ranContaining('git add --'));
    }

    public function test_selective_files_option_commits_only_chosen_files(): void
    {
        $this->artisan('push:github', [
            '--skip-assets' => true,
            '--message' => 'hotfix',
            '--files' => 'app/Http/Controllers/HomeController.php',
        ])
            ->expectsQuestion(' Proceed with deploying only the selected files? (y/N)', 'y')
            ->assertExitCode(0);

        $addCommands = array_filter($this->server->ran, fn ($c) => str_contains($c, 'git add --'));
        $this->assertCount(1, $addCommands);
        $add = reset($addCommands);
        $this->assertStringContainsString('HomeController.php', $add);
        $this->assertStringNotContainsString('NewFile.php', $add);
        $this->assertTrue($this->server->ranContaining('git push -u origin'));
    }

    public function test_selective_unknown_file_aborts_before_any_commit(): void
    {
        $this->artisan('push:github', [
            '--skip-assets' => true,
            '--message' => 'hotfix',
            '--files' => 'app/Ghost.php',
        ])->assertExitCode(1);

        $this->assertFalse($this->server->ranContaining('git commit'));
        $this->assertFalse($this->server->ranContaining('git push'));
    }

    public function test_interactive_only_selection(): void
    {
        $this->artisan('push:github', [
            '--skip-assets' => true,
            '--message' => 'partial work',
            '--only' => true,
        ])
            ->expectsQuestion(' Enter the numbers to deploy (e.g. 1,3,5 or 2-4, or "all") — leave empty to cancel', '2')
            ->expectsQuestion(' Proceed with deploying only the selected files? (y/N)', 'y')
            ->assertExitCode(0);

        $addCommands = array_filter($this->server->ran, fn ($c) => str_contains($c, 'git add --'));
        $this->assertCount(1, $addCommands);
        $add = reset($addCommands);
        $this->assertStringContainsString('NewFile.php', $add);
        $this->assertStringNotContainsString('HomeController.php', $add);
    }

    public function test_origin_mismatch_aborts_when_user_declines_fix(): void
    {
        $this->server->originUrl = 'https://github.com/zerofyi/some-other-repo.git';

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'work'])
            ->expectsQuestion(' Update origin to match GITHUB_REPO_URL and continue? (y/N)', 'n')
            ->assertExitCode(1);

        $this->assertFalse($this->server->ranContaining('git push'));
    }

    public function test_origin_mismatch_is_fixed_when_user_agrees(): void
    {
        $this->server->originUrl = 'https://github.com/zerofyi/some-other-repo.git';

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'work'])
            ->expectsQuestion(' Update origin to match GITHUB_REPO_URL and continue? (y/N)', 'y')
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranContaining('git remote set-url origin'));
        $this->assertTrue($this->server->ranContaining('git push -u origin'));
    }

    public function test_dry_run_never_commits_or_pushes(): void
    {
        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'work', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFalse($this->server->ranContaining('git add'));
        $this->assertFalse($this->server->ranContaining('git commit'));
        $this->assertFalse($this->server->ranContaining('git push'));
    }

    public function test_clean_workspace_pushes_without_committing(): void
    {
        $this->server->porcelain = '';

        $this->artisan('push:github', ['--skip-assets' => true])
            ->assertExitCode(0);

        $this->assertFalse($this->server->ranContaining('git commit'));
        $this->assertTrue($this->server->ranContaining('git push -u origin'));
    }

    public function test_invalid_env_fails_with_report(): void
    {
        $this->setEnvVars(['GITHUB_REPO_URL' => 'not-a-url']);

        $this->artisan('push:github', ['--skip-assets' => true])
            ->assertExitCode(1);

        $this->assertFalse($this->server->ranContaining('git push'));
    }

    /**
     * Regression (v2.1.0): push:github hard-failed without a
     * GITHUB_API_TOKEN, although it never uses one — it pushes with the
     * laptop's own git credentials.
     */
    public function test_push_without_token_succeeds(): void
    {
        $this->setEnvVars(['GITHUB_API_TOKEN' => null]);

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranContaining('git push -u origin'));
        $this->assertFalse($this->server->ranContaining('x-access-token'));
    }

    public function test_auth_failure_offers_token_retry_and_recovers(): void
    {
        $this->setEnvVars(['GITHUB_API_TOKEN' => null]);
        $this->server->failOnce['git push'] = true;
        $this->server->failOutput['git push'] = "fatal: Authentication failed for 'https://github.com/zerofyi/mobilebazar.git/'\n";

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->expectsQuestion(' Local Git credentials were denied. Try pushing with a GitHub Personal Access Token instead? (y/N)', 'y')
            ->expectsQuestion(' Paste a GitHub Personal Access Token (needs Contents: Read & Write to push)', 'ghp_retrytoken_retrytoken_1234')
            ->assertExitCode(0);

        $pushes = array_values(array_filter($this->server->ran, fn ($c) => str_contains($c, 'git push -u origin')));
        $this->assertCount(2, $pushes);

        // The retry pushed with a token URL, then the origin was restored
        // to the plain URL — the token never stays in .git/config.
        $this->assertTrue($this->server->ranContaining('x-access-token:'));
        $setUrls = array_values(array_filter($this->server->ran, fn ($c) => str_contains($c, 'git remote set-url origin')));
        $this->assertCount(2, $setUrls);
        $this->assertStringNotContainsString('x-access-token', end($setUrls));
    }

    public function test_ssh_permission_denied_offers_token_retry(): void
    {
        $this->setEnvVars(['GITHUB_API_TOKEN' => null]);
        $this->server->failOnce['git push'] = true;
        $this->server->failOutput['git push'] = "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n";

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->expectsQuestion(' Local Git credentials were denied. Try pushing with a GitHub Personal Access Token instead? (y/N)', 'y')
            ->expectsQuestion(' Paste a GitHub Personal Access Token (needs Contents: Read & Write to push)', 'ghp_retrytoken_retrytoken_1234')
            ->assertExitCode(0);

        $this->assertTrue($this->server->ranContaining('x-access-token:'));
    }

    public function test_auth_failure_declined_stops_without_retry(): void
    {
        $this->setEnvVars(['GITHUB_API_TOKEN' => null]);
        $this->server->failOnce['git push'] = true;
        $this->server->failOutput['git push'] = "fatal: Authentication failed\n";

        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->expectsQuestion(' Local Git credentials were denied. Try pushing with a GitHub Personal Access Token instead? (y/N)', 'n')
            ->assertExitCode(1);

        $pushes = array_values(array_filter($this->server->ran, fn ($c) => str_contains($c, 'git push -u origin')));
        $this->assertCount(1, $pushes);
        $this->assertFalse($this->server->ranContaining('x-access-token'));
    }

    public function test_non_auth_push_failure_does_not_offer_token_retry(): void
    {
        $this->server->failOnce['git push'] = true;
        $this->server->failOutput['git push'] = "error: failed to push some refs — non-fast-forward\n";

        // No expectsQuestion for the fallback: if it were asked, this
        // fails with "Unexpected question ... was asked".
        $this->artisan('push:github', ['--skip-assets' => true, '--message' => 'release work'])
            ->assertExitCode(1);

        $this->assertFalse($this->server->ranContaining('x-access-token'));
    }
}
