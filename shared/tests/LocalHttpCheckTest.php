<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\HostName;
use Maguari\Shared\LocalHttpCheck;
use PHPUnit\Framework\TestCase;

final class LocalHttpCheckTest extends TestCase
{
    public function testAcceptsUrlsOfTheInstanceItself(): void
    {
        $this->assertTrue(LocalHttpCheck::isValid('http://127.0.0.1:8000/health', ''));
        $this->assertTrue(LocalHttpCheck::isValid('http://[::1]/', ''));
        $this->assertTrue(LocalHttpCheck::isValid('http://localhost/api?full=1', 'geonode.example.com'));
        $this->assertTrue(LocalHttpCheck::isValid('https://127.0.0.1/', 'geonode.example.com'));
        $this->assertSame(['scheme' => 'https', 'host' => 'localhost', 'port' => null, 'target' => '/'], LocalHttpCheck::parts('HTTPS://LOCALHOST:443'));
    }

    public function testRefusesEverythingElse(): void
    {
        foreach ([
            ['http://10.0.0.5/', ''], ['http://example.com/', ''], ['http://127.0.0.2/', ''], ['http://0.0.0.0/', ''],
            ['http://127.0.0.1.nip.io/', ''], ['http://user:pass@127.0.0.1/', ''], ['http://127.0.0.1/#x', ''],
            ['ftp://127.0.0.1/', ''], ['127.0.0.1/health', ''], ['http://127.0.0.1/a b', ''], ['http://127.0.0.1:0/', ''],
            ['https://127.0.0.1/', ''], ['http://127.0.0.1/', 'localhost'], ['http://127.0.0.1/', '10.0.0.1'],
            ['http://127.0.0.1', ''], ['HTTP://127.0.0.1/', ''], ['http://127.0.0.1:80/', ''],
            ['http://127.0.0.1/' . str_repeat('a', 2040), ''],
        ] as [$url, $hostName]) {
            $this->assertFalse(LocalHttpCheck::isValid($url, $hostName), "{$url} {$hostName}");
        }
    }

    public function testTheSubjectNamesTheHostName(): void
    {
        $this->assertSame('http://127.0.0.1/', LocalHttpCheck::subject('http://127.0.0.1/', ''));
        $this->assertSame('https://127.0.0.1/health (geonode.example.com)', LocalHttpCheck::subject('https://127.0.0.1/health', 'geonode.example.com'));
    }

    public function testHostNames(): void
    {
        foreach (['example.com', 'www.example.com', 'xn--bcher-kva.example', 'a-b.c1'] as $name) {
            $this->assertTrue(HostName::isValid($name), $name);
        }

        foreach (['', 'localhost', 'Example.com', '10.0.0.1', '*.example.com', '-a.com', 'a-.com', 'a..com', 'example.com.', str_repeat('a', 64) . '.com'] as $name) {
            $this->assertFalse(HostName::isValid($name), $name);
        }
    }
}
