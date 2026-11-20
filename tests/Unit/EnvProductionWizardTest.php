<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\EnvProductionWizard;

class EnvProductionWizardTest extends TestCase
{
    // ------------------------------------------------------------ transform

    public function test_transform_forces_production_values_and_keeps_everything_else(): void
    {
        $content = implode("\n", [
            '# Local development',
            'APP_NAME=Shop',
            'APP_ENV=local',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_KEY=base64:existingkey123',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_DATABASE=shop_local',
            'LOG_LEVEL=debug',
            '',
        ]);

        $result = EnvProductionWizard::transformContent($content, 'shop.com');

        $this->assertStringContainsString('APP_ENV=production', $result['content']);
        $this->assertStringContainsString('APP_DEBUG=false', $result['content']);
        $this->assertStringContainsString('APP_URL=https://shop.com', $result['content']);
        // Untouched keys survive byte-identical, comments included.
        $this->assertStringContainsString('# Local development', $result['content']);
        $this->assertStringContainsString('APP_NAME=Shop', $result['content']);
        $this->assertStringContainsString('APP_KEY=base64:existingkey123', $result['content']);
        $this->assertStringContainsString('DB_DATABASE=shop_local', $result['content']);
        $this->assertFalse($result['key_generated']);
        $this->assertSame(['APP_ENV', 'APP_DEBUG', 'APP_URL'], $result['changes']);
    }

    public function test_transform_generates_a_key_when_missing_or_empty(): void
    {
        $missing = EnvProductionWizard::transformContent("APP_ENV=local\n", 'shop.com');
        $this->assertTrue($missing['key_generated']);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $missing['content']);
        $this->assertContains('APP_KEY', $missing['changes']);

        $empty = EnvProductionWizard::transformContent("APP_ENV=local\nAPP_KEY=\n", 'shop.com');
        $this->assertTrue($empty['key_generated']);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $empty['content']);
    }

    public function test_transform_disables_local_only_toggles(): void
    {
        $result = EnvProductionWizard::transformContent(
            "APP_ENV=local\nDEBUGBAR_ENABLED=true\nTELESCOPE_ENABLED=true\n",
            'shop.com'
        );

        $this->assertStringContainsString('DEBUGBAR_ENABLED=false', $result['content']);
        $this->assertStringContainsString('TELESCOPE_ENABLED=false', $result['content']);
    }

    // ---------------------------------------------------------- placeholders

    public function test_is_placeholder_flags_empties_and_known_literals(): void
    {
        foreach ([null, '', 'password', 'secret', 'changeme', 'example', 'laravel', 'root', 'NULL'] as $value) {
            $this->assertTrue(EnvProductionWizard::isPlaceholder($value), var_export($value, true) . ' should be a placeholder');
        }
    }

    public function test_is_placeholder_accepts_real_values(): void
    {
        foreach (['prod_db', 'u123456789_shop', 'S3cure-P4ss!', 'localhost', '127.0.0.1'] as $value) {
            $this->assertFalse(EnvProductionWizard::isPlaceholder($value), $value . ' should NOT be a placeholder');
        }
    }

    // ------------------------------------------------------------------ scan

    private function goodVars(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://shop.com',
            'APP_KEY' => 'base64:existingkey123',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_DATABASE' => 'u123456789_shop',
            'DB_USERNAME' => 'u123456789_shop',
            'DB_PASSWORD' => 'S3cure-P4ss!',
        ];
    }

    public function test_scan_passes_a_complete_production_file(): void
    {
        $scan = EnvProductionWizard::scan($this->goodVars(), 'shop.com');
        $this->assertSame([], $scan['blockers']);
        $this->assertSame([], $scan['warnings']);
    }

    public function test_scan_blocks_missing_database_credentials(): void
    {
        $vars = $this->goodVars();
        unset($vars['DB_PASSWORD']);
        $vars['DB_DATABASE'] = 'laravel';
        $vars['DB_USERNAME'] = 'root';

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertArrayHasKey('DB_PASSWORD', $scan['blockers']);
        $this->assertArrayHasKey('DB_DATABASE', $scan['blockers']);
        $this->assertArrayHasKey('DB_USERNAME', $scan['blockers']);
    }

    public function test_scan_blocks_a_missing_app_key(): void
    {
        $vars = $this->goodVars();
        unset($vars['APP_KEY']);

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertArrayHasKey('APP_KEY', $scan['blockers']);
    }

    public function test_scan_warns_but_does_not_block_a_localhost_app_url(): void
    {
        $vars = $this->goodVars();
        $vars['APP_URL'] = 'http://localhost';

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertSame([], $scan['blockers']);
        $this->assertArrayHasKey('APP_URL', $scan['warnings']);
    }

    public function test_scan_skips_mysql_checks_for_sqlite(): void
    {
        $vars = $this->goodVars();
        $vars['DB_CONNECTION'] = 'sqlite';
        unset($vars['DB_DATABASE'], $vars['DB_USERNAME'], $vars['DB_PASSWORD']);

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertSame([], $scan['blockers']);
        $this->assertArrayHasKey('DB_CONNECTION', $scan['warnings']);
    }

    public function test_scan_warns_on_non_production_env_and_debug_mode(): void
    {
        $vars = $this->goodVars();
        $vars['APP_ENV'] = 'local';
        $vars['APP_DEBUG'] = 'true';

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertSame([], $scan['blockers']);
        $this->assertArrayHasKey('APP_ENV', $scan['warnings']);
        $this->assertArrayHasKey('APP_DEBUG', $scan['warnings']);
    }

    public function test_scan_warns_on_log_mailer_and_debug_log_level(): void
    {
        $vars = $this->goodVars();
        $vars['MAIL_MAILER'] = 'log';
        $vars['LOG_LEVEL'] = 'debug';

        $scan = EnvProductionWizard::scan($vars, 'shop.com');
        $this->assertSame([], $scan['blockers']);
        $this->assertArrayHasKey('MAIL_MAILER', $scan['warnings']);
        $this->assertArrayHasKey('LOG_LEVEL', $scan['warnings']);
    }

    // ------------------------------------------------------------- gitignore

    public function test_ensure_gitignored_creates_appends_and_detects(): void
    {
        $dir = sys_get_temp_dir() . '/shipit-wizard-' . uniqid();
        mkdir($dir);

        try {
            $this->assertSame('created', EnvProductionWizard::ensureGitignored($dir));
            $this->assertStringContainsString('.env.production', (string) file_get_contents($dir . '/.gitignore'));

            // Second call finds it already covered.
            $this->assertSame('already', EnvProductionWizard::ensureGitignored($dir));

            // A .gitignore without the line gets it appended.
            file_put_contents($dir . '/.gitignore', "/node_modules\n/vendor\n");
            $this->assertSame('appended', EnvProductionWizard::ensureGitignored($dir));
            $this->assertStringContainsString('.env.production', (string) file_get_contents($dir . '/.gitignore'));
            $this->assertStringContainsString('/vendor', (string) file_get_contents($dir . '/.gitignore'));

            // The .env* glob already covers it.
            file_put_contents($dir . '/.gitignore', ".env*\n");
            $this->assertSame('already', EnvProductionWizard::ensureGitignored($dir));
        } finally {
            @unlink($dir . '/.gitignore');
            @rmdir($dir);
        }
    }

    // -------------------------------------------------------------- app key

    public function test_generated_app_key_has_the_laravel_format(): void
    {
        $key = EnvProductionWizard::generateAppKey();
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen((string) base64_decode(substr($key, 7), true)));
    }
}
