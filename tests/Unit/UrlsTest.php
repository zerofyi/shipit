<?php

namespace Zerofyi\ShipIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zerofyi\ShipIt\Support\Urls;

class UrlsTest extends TestCase
{
    public function test_validates_https_and_ssh_urls(): void
    {
        $this->assertTrue(Urls::isValidRepoUrl('https://github.com/zerofyi/mobilebazar.git'));
        $this->assertTrue(Urls::isValidRepoUrl('https://github.com/zerofyi/mobilebazar'));
        $this->assertTrue(Urls::isValidRepoUrl('git@github.com:zerofyi/mobilebazar.git'));
        $this->assertFalse(Urls::isValidRepoUrl(''));
        $this->assertFalse(Urls::isValidRepoUrl(null));
        $this->assertFalse(Urls::isValidRepoUrl('not a url'));
        $this->assertFalse(Urls::isValidRepoUrl('github.com/zerofyi/mobilebazar'));
        $this->assertFalse(Urls::isValidRepoUrl('ftp://github.com/x/y'));
    }

    public function test_converts_https_to_ssh_and_adds_git_suffix(): void
    {
        $this->assertSame('git@github.com:zerofyi/mobilebazar.git', Urls::toSshUrl('https://github.com/zerofyi/mobilebazar'));
        $this->assertSame('git@github.com:zerofyi/mobilebazar.git', Urls::toSshUrl('https://github.com/zerofyi/mobilebazar.git'));
        $this->assertSame('git@github.com:zerofyi/mobilebazar.git', Urls::toSshUrl('git@github.com:zerofyi/mobilebazar.git'));
        $this->assertSame('git@gitlab.com:team/app.git', Urls::toSshUrl('https://gitlab.com/team/app'));
    }

    public function test_extracts_owner_and_repo(): void
    {
        $this->assertSame(['zerofyi', 'mobilebazar'], Urls::ownerRepo('https://github.com/zerofyi/mobilebazar.git'));
        $this->assertSame(['zerofyi', 'mobilebazar'], Urls::ownerRepo('git@github.com:zerofyi/mobilebazar.git'));
        $this->assertSame(['zerofyi', 'mobilebazar'], Urls::ownerRepo('https://github.com/zerofyi/mobilebazar'));
        $this->assertNull(Urls::ownerRepo('https://example.com/somewhere/else'));
    }

    public function test_same_repository_across_protocols(): void
    {
        $this->assertTrue(Urls::sameRepository(
            'https://github.com/zerofyi/mobilebazar.git',
            'git@github.com:zerofyi/mobilebazar.git'
        ));
        $this->assertTrue(Urls::sameRepository(
            'https://github.com/zerofyi/mobilebazar',
            'https://github.com/zerofyi/mobilebazar.git'
        ));
        $this->assertTrue(Urls::sameRepository(
            'https://github.com/zerofyi/mobilebazar.git/',
            'https://github.com/zerofyi/mobilebazar.git'
        ));
        $this->assertFalse(Urls::sameRepository(
            'https://github.com/zerofyi/mobilebazar.git',
            'https://github.com/zerofyi/other.git'
        ));
        $this->assertFalse(Urls::sameRepository(
            'https://github.com/zerofyi/mobilebazar.git',
            'https://github.com/someoneelse/mobilebazar.git'
        ));
    }

    public function test_detects_and_strips_embedded_credentials(): void
    {
        $tokenUrl = 'https://x-access-token:ghp_secret123@github.com/zerofyi/mobilebazar.git';
        $this->assertTrue(Urls::containsCredentials($tokenUrl));
        $this->assertFalse(Urls::containsCredentials('https://github.com/zerofyi/mobilebazar.git'));
        $this->assertFalse(Urls::containsCredentials('git@github.com:zerofyi/mobilebazar.git'));
        $this->assertSame(
            'https://github.com/zerofyi/mobilebazar.git',
            Urls::stripCredentials($tokenUrl)
        );
    }

    public function test_host_extraction(): void
    {
        $this->assertSame('github.com', Urls::hostOf('git@github.com:zerofyi/mobilebazar.git'));
        $this->assertSame('gitlab.com', Urls::hostOf('https://gitlab.com/team/app.git'));
        $this->assertSame('github.com', Urls::hostOf('garbage'));
    }
}
