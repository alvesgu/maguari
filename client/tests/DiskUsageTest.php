<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\DiskUsage;
use Maguari\Client\StatvfsFilesystemStats;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class DiskUsageTest extends TestCase
{
    private const GCE = __DIR__ . '/fixtures/mounts-gce';

    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    private function mountsFile(string $contents): string
    {
        $path = $this->directory->path . '/mounts';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testKeepsRealLocalFilesystemsEachDeviceOnce(): void
    {
        $this->assertSame(
            ['/', '/boot', '/boot/efi', '/mnt/disks/data files', '/mnt/backup'],
            (new DiskUsage(new FakeFilesystemStats(), self::GCE))->mountPoints(),
        );
    }

    public function testAContainerHasNothingToMeasure(): void
    {
        $this->assertSame([], (new DiskUsage(new FakeFilesystemStats(), __DIR__ . '/fixtures/mounts-container'))->mountPoints());
    }

    public function testDecodesOctalEscapes(): void
    {
        $path = $this->mountsFile(
            "/dev/sdb /mnt/a\\134b\\040c ext4 rw 0 0\n/dev/sdc /mnt/a\\011tab ext4 rw 0 0\n/dev/sdd /mnt/new\\012line ext4 rw 0 0\n",
        );

        // A tab or newline would make the server reject the whole heartbeat.
        $this->assertSame(['/mnt/a\\b c'], (new DiskUsage(new FakeFilesystemStats(), $path))->mountPoints());
    }

    public function testSkipsMountPointsTheServerWouldReject(): void
    {
        $long = '/' . str_repeat('a', 1024);
        $path = $this->mountsFile("/dev/sdb {$long} ext4 rw 0 0\n/dev/sdb /ok ext4 rw 0 0\n/dev/sdc relative ext4 rw 0 0\n");

        $this->assertSame(['/ok'], (new DiskUsage(new FakeFilesystemStats(), $path))->mountPoints());
    }

    public function testReportsAtMost20Filesystems(): void
    {
        $lines = '';

        for ($i = 0; $i < 25; $i++) {
            $lines .= "/dev/sd{$i} /mnt/{$i} ext4 rw 0 0\n";
        }

        $mountPoints = (new DiskUsage(new FakeFilesystemStats(), $this->mountsFile($lines)))->mountPoints();

        $this->assertCount(DiskUsage::MAX_FILESYSTEMS, $mountPoints);
        $this->assertSame('/mnt/19', $mountPoints[19]);
    }

    public function testAMissingMountsFileGivesNoReadings(): void
    {
        $this->assertSame([], (new DiskUsage(new FakeFilesystemStats(), $this->directory->path . '/missing'))->readings());
    }

    public function testUsedIsTotalMinusFree(): void
    {
        $stats = new FakeFilesystemStats([
            '/' => [10_213_466_112, 2_090_009_600],
            '/boot' => [919_158_784, 806_813_696],
        ]);

        $this->assertSame([
            ['metric' => 'disk_used_bytes:/', 'value' => 8_123_456_512],
            ['metric' => 'disk_total_bytes:/', 'value' => 10_213_466_112],
            ['metric' => 'disk_used_bytes:/boot', 'value' => 112_345_088],
            ['metric' => 'disk_total_bytes:/boot', 'value' => 919_158_784],
        ], (new DiskUsage($stats, self::GCE))->readings());
        // The others could not be measured and are skipped.
        $this->assertSame(['/', '/boot', '/boot/efi', '/mnt/disks/data files', '/mnt/backup'], $stats->measured);
    }

    public function testMeasuresTheRealRootFilesystem(): void
    {
        $measured = (new StatvfsFilesystemStats())->measure('/');

        $this->assertNotNull($measured);
        [$total, $free] = $measured;
        $this->assertGreaterThan(0, $total);
        $this->assertGreaterThanOrEqual(0, $free);
        $this->assertLessThanOrEqual($total, $free);
        $this->assertNull((new StatvfsFilesystemStats())->measure($this->directory->path . '/missing'));
    }
}
