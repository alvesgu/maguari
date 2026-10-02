<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Server\Monitoring\Domain\Reading;
use Maguari\Server\Monitoring\Domain\Readings;
use PHPUnit\Framework\TestCase;

final class ReadingsTest extends TestCase
{
    /**
     * @return array{metric: string, value: mixed}
     */
    private static function reading(string $metric, mixed $value): array
    {
        return ['metric' => $metric, 'value' => $value];
    }

    /**
     * @param list<mixed> $items
     * @return array<string, array{int, int}> value and deadband, by metric
     */
    private static function parsed(array $items): array
    {
        $parsed = [];

        foreach (Readings::parse($items)->readings as $reading) {
            $parsed[$reading->metric] = [$reading->value, $reading->deadband];
        }

        return $parsed;
    }

    public function testAcceptsDiskReadings(): void
    {
        $this->assertSame([
            'disk_used_bytes:/' => [8_123_456_512, 10_213_466],
            'disk_total_bytes:/' => [10_213_466_112, 0],
            'disk_used_bytes:/boot' => [0, 919_158],
            'disk_total_bytes:/boot' => [919_158_784, 0],
        ], self::parsed([
            self::reading('disk_used_bytes:/', 8_123_456_512),
            self::reading('disk_total_bytes:/', 10_213_466_112),
            self::reading('disk_used_bytes:/boot', 0),
            self::reading('disk_total_bytes:/boot', 919_158_784),
        ]));
    }

    public function testAcceptsCertificateReadingsWithoutADeadband(): void
    {
        $this->assertSame([
            'certificate_expires_at:example.com' => [1_797_000_000, 0],
            'certificate_expires_at:*.example.org' => [1_798_000_000, 0],
        ], self::parsed([
            self::reading('certificate_expires_at:example.com', 1_797_000_000),
            self::reading('certificate_expires_at:*.example.org', 1_798_000_000),
        ]));
    }

    public function testAcceptsNoReadings(): void
    {
        $this->assertSame([], Readings::parse([])->readings);
    }

    public function testDiskTotalAloneIsAccepted(): void
    {
        $this->assertSame(['disk_total_bytes:/' => [10, 0]], self::parsed([self::reading('disk_total_bytes:/', 10)]));
    }

    public function testAMountPointMayContainTheSeparatorAndSpaces(): void
    {
        $this->assertSame(
            ['disk_used_bytes:/mnt/a:b c' => [5, 2], 'disk_total_bytes:/mnt/a:b c' => [2000, 0]],
            self::parsed([self::reading('disk_used_bytes:/mnt/a:b c', 5), self::reading('disk_total_bytes:/mnt/a:b c', 2000)]),
        );
    }

    public function testUnknownKindsAreIgnored(): void
    {
        $this->assertSame(['disk_total_bytes:/' => [10, 0]], self::parsed([
            self::reading('load_average:1m', 3),
            self::reading('uptime_seconds', 100),
            self::reading('disk_total_bytes:/', 10),
        ]));
    }

    public function testAcceptsTheMaximumNumberOfReadings(): void
    {
        $items = [];

        for ($i = 0; $i < Readings::MAX_READINGS; $i++) {
            $items[] = self::reading('disk_total_bytes:/mnt/' . $i, $i);
        }

        $this->assertCount(100, Readings::parse($items)->readings);
    }

    public function testAcceptsTheLongestMountPoint(): void
    {
        $mountPoint = '/' . str_repeat('a', Readings::MAX_MOUNT_POINT_BYTES - 1);

        $this->assertCount(1, Readings::parse([self::reading('disk_total_bytes:' . $mountPoint, 1)])->readings);
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function invalid(): array
    {
        $tooMany = [];

        for ($i = 0; $i <= Readings::MAX_READINGS; $i++) {
            $tooMany[] = self::reading('unknown:' . $i, 1);
        }

        return [
            'not an object' => [[42]],
            'a list' => [[['disk_total_bytes:/', 1]]],
            'no metric' => [[['value' => 1]]],
            'metric not a string' => [[self::reading('1', 1), ['metric' => 1, 'value' => 1]]],
            'no value' => [[['metric' => 'disk_total_bytes:/']]],
            'value as a string' => [[self::reading('disk_total_bytes:/', '1')]],
            'value as a float' => [[self::reading('disk_total_bytes:/', 1.5)]],
            'value too large for an integer' => [[self::reading('disk_total_bytes:/', 1e19)]],
            'value null' => [[self::reading('disk_total_bytes:/', null)]],
            'negative value' => [[self::reading('disk_total_bytes:/', -1)]],
            'negative value of an unknown kind' => [[self::reading('load_average:1m', -1)]],
            'disk metric without a mount point' => [[self::reading('disk_total_bytes', 1)]],
            'empty mount point' => [[self::reading('disk_total_bytes:', 1)]],
            'relative mount point' => [[self::reading('disk_total_bytes:boot', 1)]],
            'mount point too long' => [[self::reading('disk_total_bytes:/' . str_repeat('a', 1024), 1)]],
            'newline in the mount point' => [[self::reading("disk_total_bytes:/mnt\n", 1)]],
            'NUL in the mount point' => [[self::reading("disk_total_bytes:/mnt\0x", 1)]],
            'DEL in the mount point' => [[self::reading("disk_total_bytes:/mnt\x7f", 1)]],
            'certificate metric without a domain' => [[self::reading('certificate_expires_at', 1)]],
            'certificate metric with an empty domain' => [[self::reading('certificate_expires_at:', 1)]],
            'certificate domain in uppercase' => [[self::reading('certificate_expires_at:Example.com', 1)]],
            'certificate domain with a space' => [[self::reading('certificate_expires_at:example .com', 1)]],
            'negative expiry' => [[self::reading('certificate_expires_at:example.com', -1)]],
            'the same metric twice' => [[self::reading('disk_total_bytes:/', 1), self::reading('disk_total_bytes:/', 1)]],
            'disk used without its total' => [[self::reading('disk_used_bytes:/', 1), self::reading('disk_total_bytes:/boot', 1)]],
            'too many' => [$tooMany],
        ];
    }

    /**
     * @dataProvider invalid
     * @param list<mixed> $items
     */
    public function testRejects(array $items): void
    {
        $this->expectException(InvalidReadings::class);

        Readings::parse($items);
    }

    public function testTheDiskUsedDeadbandIsOneThousandthOfTheTotal(): void
    {
        $readings = Readings::parse([self::reading('disk_total_bytes:/', 10_000_000_000), self::reading('disk_used_bytes:/', 1)])->readings;

        $this->assertEquals(
            [new Reading('disk_total_bytes:/', 10_000_000_000, 0), new Reading('disk_used_bytes:/', 1, 10_000_000)],
            $readings,
        );
    }
}
