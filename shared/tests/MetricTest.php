<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\Metric;
use PHPUnit\Framework\TestCase;

final class MetricTest extends TestCase
{
    public function testName(): void
    {
        $this->assertSame('disk_used_bytes:/boot', Metric::name(Metric::DISK_USED_BYTES, '/boot'));
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function names(): array
    {
        return [
            'root' => ['disk_used_bytes:/', 'disk_used_bytes', '/'],
            'colon in the mount point' => ['disk_total_bytes:/mnt/a:b', 'disk_total_bytes', '/mnt/a:b'],
            'empty subject' => ['disk_used_bytes:', 'disk_used_bytes', ''],
            'no separator' => ['uptime_seconds', 'uptime_seconds', null],
        ];
    }

    /**
     * @dataProvider names
     */
    public function testSplitsAtTheFirstSeparator(string $metric, string $kind, ?string $subject): void
    {
        $this->assertSame([$kind, $subject], Metric::split($metric));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function certificateDomains(): array
    {
        return [
            'domain' => ['example.com', true],
            'subdomain with digits and hyphens' => ['www-2.example.com', true],
            'single label' => ['intranet', true],
            'wildcard' => ['*.example.com', true],
            'punycode' => ['xn--bcher-kva.example', true],
            'longest' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61), true],
            'too long' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 62), false],
            'empty' => ['', false],
            'uppercase' => ['Example.com', false],
            'empty label' => ['example..com', false],
            'leading dot' => ['.example.com', false],
            'trailing dot' => ['example.com.', false],
            'wildcard not first' => ['www.*.example.com', false],
            'bare wildcard' => ['*', false],
            'space' => ['example .com', false],
            'newline at the end' => ["example.com\n", false],
            'non-ASCII' => ['bücher.example', false],
            'slash' => ['example.com/a', false],
        ];
    }

    /**
     * @dataProvider certificateDomains
     */
    public function testCertificateDomains(string $domain, bool $valid): void
    {
        $this->assertSame($valid, Metric::isCertificateDomain($domain));
    }
}
