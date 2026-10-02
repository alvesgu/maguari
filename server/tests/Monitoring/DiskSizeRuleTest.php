<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring;

use Maguari\Server\Monitoring\CheckOutcome;
use Maguari\Server\Monitoring\CheckResult;
use Maguari\Server\Monitoring\DiskSizeRule;
use Maguari\Server\Monitoring\MetricRun;
use PHPUnit\Framework\TestCase;

final class DiskSizeRuleTest extends TestCase
{
    private const AT = 1_790_000_000;
    private const GIB = 1024 ** 3;

    private static function total(int|float $value, int $endAt = self::AT): MetricRun
    {
        return new MetricRun(1, $value, $endAt - 3600, $endAt);
    }

    /**
     * @param array<string, ?MetricRun> $totals
     */
    private static function check(?int $bootDiskBytes, array $totals, ?string $problem = null): CheckResult
    {
        return (new DiskSizeRule())->check(7, $problem, $bootDiskBytes, $totals + ['/' => null, '/boot' => null, '/boot/efi' => null], self::AT);
    }

    public function testFilesystemsFillingTheDiskPass(): void
    {
        $result = self::check(15 * self::GIB, ['/' => self::total(14.4 * self::GIB), '/boot/efi' => self::total(105 * 1024 ** 2)]);

        $this->assertEquals(
            new CheckResult(7, 'disk_size', CheckOutcome::Pass, 'The boot disk is 15.0 GiB and its filesystems total 14.5 GiB.', self::AT),
            $result,
        );
    }

    public function testExactlyTenPercentUnaccountedPasses(): void
    {
        $this->assertSame(CheckOutcome::Pass, self::check(10 * self::GIB, ['/' => self::total(9 * self::GIB)])->outcome);
    }

    public function testMoreThanTenPercentUnaccountedFails(): void
    {
        $result = self::check(10 * self::GIB, ['/' => self::total(9 * self::GIB - 1)]);

        $this->assertSame(CheckOutcome::Fail, $result->outcome);
        $this->assertSame(
            'The boot disk is 10.0 GiB, but its filesystems total 9.0 GiB. Rebooting usually extends them (cloud-init); otherwise run growpart and resize2fs.',
            $result->detail,
        );
    }

    public function testAGrownDiskFails(): void
    {
        // A 10 GiB Ubuntu disk grown to 11 GiB, filesystems not extended.
        $result = self::check(11 * self::GIB, ['/' => self::total(9.5 * self::GIB), '/boot/efi' => self::total(105 * 1024 ** 2)]);

        $this->assertSame(CheckOutcome::Fail, $result->outcome);
    }

    public function testBootFilesystemsCount(): void
    {
        // Ubuntu 24.04 images have a separate /boot: / alone is below 90%.
        $totals = ['/' => self::total(8.6 * self::GIB), '/boot' => self::total(0.9 * self::GIB), '/boot/efi' => self::total(0.1 * self::GIB)];

        $this->assertSame(CheckOutcome::Pass, self::check(10 * self::GIB, $totals)->outcome);
        $this->assertSame(CheckOutcome::Fail, self::check(10 * self::GIB, ['/' => $totals['/']])->outcome);
    }

    public function testFilesystemsLargerThanTheDiskPass(): void
    {
        $this->assertSame(CheckOutcome::Pass, self::check(10 * self::GIB, ['/' => self::total(12 * self::GIB)])->outcome);
    }

    public function testReadingsUpToADayOldAreUsed(): void
    {
        $result = self::check(10 * self::GIB, ['/' => self::total(9.6 * self::GIB, self::AT - 86_400)]);

        $this->assertSame(CheckOutcome::Pass, $result->outcome);
    }

    public function testNoRecentRootReadingIsNotChecked(): void
    {
        foreach ([[], ['/' => self::total(9.6 * self::GIB, self::AT - 86_401)], ['/boot' => self::total(0.9 * self::GIB)]] as $totals) {
            $result = self::check(10 * self::GIB, $totals);

            $this->assertSame(CheckOutcome::NotChecked, $result->outcome);
            $this->assertSame('No disk readings in the last 24 hours.', $result->detail);
        }
    }

    public function testOldReadingsOfOtherFilesystemsAreLeftOut(): void
    {
        // An old /boot run (for example a filesystem since unmounted) is not
        // added, so / alone is compared.
        $result = self::check(10 * self::GIB, ['/' => self::total(8.6 * self::GIB), '/boot' => self::total(0.9 * self::GIB, self::AT - 86_401)]);

        $this->assertSame('The boot disk is 10.0 GiB, but its filesystems total 8.6 GiB. Rebooting usually extends them (cloud-init); otherwise run growpart and resize2fs.', $result->detail);
    }

    public function testFleetProblemIsNotChecked(): void
    {
        $result = self::check(null, ['/' => self::total(9 * self::GIB)], 'The Compute Engine API is not enabled in this project.');

        $this->assertSame(CheckOutcome::NotChecked, $result->outcome);
        $this->assertSame('The Compute Engine API is not enabled in this project.', $result->detail);
    }

    public function testUnknownBootDiskSizeIsNotChecked(): void
    {
        $result = self::check(null, ['/' => self::total(9 * self::GIB)]);

        $this->assertSame(CheckOutcome::NotChecked, $result->outcome);
        $this->assertSame('Google Cloud reported no boot disk size for this instance.', $result->detail);
    }
}
