<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Cli;

use Maguari\Server\Cli\DailyJobReport;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use PHPUnit\Framework\TestCase;

final class DailyJobReportTest extends TestCase
{
    private const AT = 1_791_439_200;

    public function testOneLinePerInstanceAndCheckThenASummary(): void
    {
        $this->assertSame([
            'p/us-east1-b/web: Disk size: Pass. The boot disk is 10.0 GiB and its filesystems total 9.6 GiB.',
            'p/us-east1-b/web: Certificate example.com: Pass. Valid until 2026-12-07 (60 days).',
            'p/us-east1-b/web: Certificate www.example.com: Fail. Expires on 2026-10-12, in 4 days.',
            'p/us-east1-b/db: Disk size: Not checked. No disk readings in the last 24 hours.',
            'instance 9: Disk size: Pass. Fine.',
            'Daily job finished: 3 passed, 1 failed, 1 not checked.',
        ], DailyJobReport::lines([
            new CheckResult(1, 'disk_size', CheckOutcome::Pass, 'The boot disk is 10.0 GiB and its filesystems total 9.6 GiB.', self::AT),
            new CheckResult(1, 'local_certificate', CheckOutcome::Pass, 'Valid until 2026-12-07 (60 days).', self::AT, 'example.com'),
            new CheckResult(1, 'local_certificate', CheckOutcome::Fail, 'Expires on 2026-10-12, in 4 days.', self::AT, 'www.example.com'),
            new CheckResult(2, 'disk_size', CheckOutcome::NotChecked, 'No disk readings in the last 24 hours.', self::AT),
            // An instance removed while the job ran has no name.
            new CheckResult(9, 'disk_size', CheckOutcome::Pass, 'Fine.', self::AT),
        ], [1 => 'p/us-east1-b/web', 2 => 'p/us-east1-b/db']));
    }

    public function testNoInstances(): void
    {
        $this->assertSame(
            ['No instances to check.', 'Daily job finished: 0 passed, 0 failed, 0 not checked.'],
            DailyJobReport::lines([], []),
        );
    }
}
