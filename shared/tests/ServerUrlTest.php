<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\ServerUrl;
use PHPUnit\Framework\TestCase;

final class ServerUrlTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function valid(): array
    {
        return [
            'https' => ['https://maguari.example.com', 'https://maguari.example.com'],
            'trailing slash' => ['https://maguari.example.com/', 'https://maguari.example.com'],
            'port' => ['https://maguari.example.com:8443', 'https://maguari.example.com:8443'],
            'uppercase' => ['HTTPS://Maguari.Example.COM', 'https://maguari.example.com'],
            'http on localhost' => ['http://localhost:8080', 'http://localhost:8080'],
            'http on LOCALHOST' => ['http://LOCALHOST', 'http://localhost'],
            'https on localhost' => ['https://localhost', 'https://localhost'],
        ];
    }

    /**
     * @dataProvider valid
     */
    public function testAccepts(string $url, string $normalized): void
    {
        $this->assertSame($normalized, ServerUrl::normalize($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'http elsewhere' => ['http://maguari.example.com'],
            'http on 127.0.0.1' => ['http://127.0.0.1:8080'],
            'http on ::1' => ['http://[::1]:8080'],
            'http on a localhost subdomain' => ['http://localhost.example.com'],
            'http on localhost with a trailing dot' => ['http://localhost.'],
            'other scheme' => ['ftp://maguari.example.com'],
            'no scheme' => ['maguari.example.com'],
            'path' => ['https://maguari.example.com/maguari'],
            'query' => ['https://maguari.example.com/?a=b'],
            'empty query' => ['https://maguari.example.com?'],
            'fragment' => ['https://maguari.example.com#top'],
            'user' => ['https://user@maguari.example.com'],
            'password' => ['https://user:secret@maguari.example.com'],
            'empty' => [''],
            'space in host' => ['https://maguari example.com'],
            'newline' => ["https://maguari.example.com\n"],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testRejects(string $url): void
    {
        $this->assertNull(ServerUrl::normalize($url));
    }
}
