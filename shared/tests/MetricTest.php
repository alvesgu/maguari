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
}
