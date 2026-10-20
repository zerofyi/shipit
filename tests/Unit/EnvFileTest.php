<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\EnvFile;

class EnvFileTest extends TestCase
{
    public function test_parse_handles_quotes_comments_and_exports(): void
    {
        $content = implode("\n", [
            '# a comment',
            'APP_NAME=My App',
            'APP_KEY="base64:abc=def"',
            "QUOTED='single quoted'",
            'export EXPORTED=yes',
            'INLINE=value # trailing comment',
            'EMPTY=',
            'NO_EQUALS_LINE',
            '',
        ]);

        $vars = EnvFile::parse($content);

        $this->assertSame('My App', $vars['APP_NAME']);
        $this->assertSame('base64:abc=def', $vars['APP_KEY']);
        $this->assertSame('single quoted', $vars['QUOTED']);
        $this->assertSame('yes', $vars['EXPORTED']);
        $this->assertSame('value', $vars['INLINE']);
        $this->assertSame('', $vars['EMPTY']);
        $this->assertArrayNotHasKey('NO_EQUALS_LINE', $vars);
    }

    public function test_diff_classifies_keys(): void
    {
        $local = ['A' => '1', 'B' => '2', 'C' => '3'];
        $server = ['A' => '1', 'B' => 'changed', 'D' => '4'];

        $diff = EnvFile::diff($local, $server);

        $this->assertSame(['C'], $diff['added']);
        $this->assertSame(['B'], $diff['changed']);
        $this->assertSame(['D'], $diff['removed']);
        $this->assertSame(1, $diff['unchanged']);
    }

    public function test_diff_of_identical_maps_is_empty(): void
    {
        $map = ['A' => '1', 'B' => '2'];
        $diff = EnvFile::diff($map, $map);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame(2, $diff['unchanged']);
    }
}
