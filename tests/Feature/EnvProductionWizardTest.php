<?php

namespace Zerofyi\ShipIt\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Zerofyi\ShipIt\Tests\Fakes\FakeServer;
use Zerofyi\ShipIt\Tests\TestCase;

class EnvProductionWizardTest extends TestCase
{
    private FakeServer $server;

    /** @var array<string, string|null> Local files we touched, for restore. */
    private array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidEnv();
        $this->server = new FakeServer();
        $this->server->register();
    }

    protected function tearDown(): void
    {
        foreach ($this->backups as $name => $original) {
            $path = base_path($name);
            if ($original === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $original);
            }
        }
        // The wizard itself creates .env.production (not via writeLocal),
        // so remove it here too when no original was backed up — otherwise
        // it leaks into the next test and the wizard silently reports
        // 'ready' without prompting.
        if (!array_key_exists('.env.production', $this->backups)) {
            @unlink(base_path('.env.production'));
        }
        $this->backups = [];
        parent::tearDown();
    }

    private function writeLocal(string $name, string $content): void
    {
        $path = base_path($name);
        if (!array_key_exists($name, $this->backups)) {
            $this->backups[$name] = file_exists($path) ? (string) file_get_contents($path) : null;
        }
        file_put_contents($path, $content);
    }

    private function localEnv(): string
    {
        return implode("\n", [
            'APP_NAME=Shop',
            'APP_ENV=local',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'DB_CONNECTION=mysql',
            'DB_HOST=localhost',
            'DB_DATABASE=u123456789_shop',
            'DB_USERNAME=u123456789_shop',
            'DB_PASSWORD=S3cure-P4ss!',
            '',
        ]);
    }

    // -------------------------------------------------------- push:hostinger

    public function test_first_deploy_option_one_creates_production_env_and_deploys(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->server->serverEnv = null;
        $this->writeLocal('.env', $this->localEnv());
        $this->writeLocal('.gitignore', "/node_modules\n/vendor\n");

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'first deploy'])
            ->expectsQuestion(' Choose an option (1,2,3) — leave empty to stop', '1')
            ->expectsQuestion(' Write .env.production with these production values? (Y/n)', 'y')
            ->assertExitCode(0);

        $prod = (string) file_get_contents(base_path('.env.production'));
        $this->assertStringContainsString('APP_ENV=production', $prod);
        $this->assertStringContainsString('APP_DEBUG=false', $prod);
        $this->assertStringContainsString('APP_URL=https://shop.com', $prod);
        $this->assertStringContainsString('DB_DATABASE=u123456789_shop', $prod);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:/m', $prod);

        // The transformed file is what landed on the server.
        $this->assertSame($prod, $this->server->uploadedEnv);

        // The gitignore guard ran: production secrets can never be committed.
        $this->assertStringContainsString('.env.production', (string) file_get_contents(base_path('.gitignore')));
    }

    public function test_first_deploy_stop_choice_aborts_before_any_push_or_backup(): void
    {
        $this->server->serverEnv = null;
        $this->writeLocal('.env', $this->localEnv());

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'first deploy'])
            ->expectsQuestion(' Choose an option (1,2,3) — leave empty to stop', '3')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist(base_path('.env.production'));
        $this->assertFalse($this->server->ranContaining('git push'));
        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));
        $this->assertFalse($this->server->ranRemoteContaining('.deploy.lock'));
    }

    public function test_first_deploy_dry_run_reports_the_wizard_without_prompting(): void
    {
        $this->server->serverEnv = null;

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'deploy', '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] No local .env.production')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(base_path('.env.production'));
    }

    public function test_first_deploy_option_two_with_complete_values_deploys(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->server->serverEnv = null;
        $this->writeLocal('.env.example', implode("\n", [
            'APP_NAME=Shop',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=https://shop.com',
            'APP_KEY=base64:examplekey123',
            'DB_CONNECTION=mysql',
            'DB_HOST=localhost',
            'DB_DATABASE=u123456789_shop',
            'DB_USERNAME=u123456789_shop',
            'DB_PASSWORD=S3cure-P4ss!',
            '',
        ]));

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'first deploy'])
            ->expectsQuestion(' Choose an option (2,3) — leave empty to stop', '2')
            ->assertExitCode(0);

        $this->assertStringContainsString('APP_KEY=base64:examplekey123', (string) file_get_contents(base_path('.env.production')));
        $this->assertNotNull($this->server->uploadedEnv);
    }

    public function test_first_deploy_option_two_can_continue_past_blockers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->server->serverEnv = null;
        $this->writeLocal('.env.example', implode("\n", [
            'APP_NAME=Shop',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=https://shop.com',
            'DB_CONNECTION=mysql',
            'DB_HOST=localhost',
            'DB_DATABASE=',
            'DB_USERNAME=',
            'DB_PASSWORD=',
            '',
        ]));

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'first deploy'])
            ->expectsQuestion(' Choose an option (2,3) — leave empty to stop', '2')
            ->expectsQuestion(" Press ENTER to re-check, type 'continue' to deploy anyway, or 'stop' to quit", 'continue')
            ->expectsOutputToContain('DB_PASSWORD')
            ->assertExitCode(0);
    }

    public function test_first_deploy_option_two_stop_in_step_two_aborts(): void
    {
        $this->server->serverEnv = null;
        $this->writeLocal('.env.example', "APP_NAME=Shop\nDB_DATABASE=\n");

        $this->artisan('push:hostinger', ['--skip-assets' => true, '--message' => 'first deploy'])
            ->expectsQuestion(' Choose an option (2,3) — leave empty to stop', '2')
            ->expectsQuestion(" Press ENTER to re-check, type 'continue' to deploy anyway, or 'stop' to quit", 'stop')
            ->assertExitCode(1);

        // Step 1 happened (the file was created from the example)...
        $this->assertFileExists(base_path('.env.production'));
        // ...but nothing was pushed or backed up.
        $this->assertFalse($this->server->ranContaining('git push'));
        $this->assertFalse($this->server->ranRemoteContaining('BACKUP_OK'));
    }

    // ---------------------------------------------------------- shipit:doctor

    public function test_doctor_offers_the_wizard_on_a_first_deploy(): void
    {
        $this->server->serverEnv = null;
        $this->server->doctorFacts['env_exists'] = '0';
        $this->writeLocal('.env', $this->localEnv());

        $this->artisan('shipit:doctor')
            ->expectsQuestion(' Create .env.production now with the setup wizard? (y/N)', 'y')
            ->expectsQuestion(' Choose an option (1,2,3) — leave empty to stop', '1')
            ->expectsQuestion(' Write .env.production with these production values? (Y/n)', 'y')
            ->expectsOutputToContain('Local .env.production present')
            ->assertExitCode(0);

        $this->assertFileExists(base_path('.env.production'));
    }

    public function test_doctor_warns_on_an_unsafe_production_file(): void
    {
        $this->server->serverEnv = null;
        $this->server->doctorFacts['env_exists'] = '0';
        $this->writeLocal('.env.production', implode("\n", [
            'APP_ENV=production',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_KEY=base64:existingkey123',
            'DB_CONNECTION=mysql',
            'DB_DATABASE=u123456789_shop',
            'DB_USERNAME=u123456789_shop',
            'DB_PASSWORD=',
            '',
        ]));

        $this->artisan('shipit:doctor')
            ->expectsOutputToContain('Local .env.production: DB_PASSWORD')
            ->expectsOutputToContain('Local .env.production: APP_DEBUG')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------- shipit:env

    public function test_env_sync_offers_the_wizard_when_the_file_is_missing(): void
    {
        $this->writeLocal('.env', $this->localEnv());

        $this->artisan('shipit:env')
            ->expectsQuestion(' No local .env.production found. Create it now with the setup wizard? (y/N)', 'y')
            ->expectsQuestion(' Choose an option (1,2,3) — leave empty to stop', '1')
            ->expectsQuestion(' Write .env.production with these production values? (Y/n)', 'y')
            ->expectsQuestion(' Apply this sync to the server .env? (y/N)', 'y')
            ->assertExitCode(0);

        $this->assertNotNull($this->server->uploadedEnv);
        $this->assertStringContainsString('APP_ENV=production', (string) $this->server->uploadedEnv);
    }

    public function test_env_sync_declining_the_wizard_still_fails(): void
    {
        $this->artisan('shipit:env')
            ->expectsQuestion(' No local .env.production found. Create it now with the setup wizard? (y/N)', 'n')
            ->assertExitCode(1);
    }
}
