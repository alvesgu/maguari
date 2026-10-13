<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Cli;

use Maguari\Server\Cli\TickReport;
use Maguari\Server\Kernel\Events\DeliveredEvent;
use Maguari\Server\Kernel\Events\DeliveryReport;
use Maguari\Server\Kernel\Events\FailedDelivery;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\Reconciliation;
use PHPUnit\Framework\TestCase;

final class TickReportTest extends TestCase
{
    public function testNothingWhenNothingHappened(): void
    {
        $report = new DeliveryReport([], [], false);

        $this->assertSame([], TickReport::lines($report));
        $this->assertSame([], TickReport::errorLines($report));
    }

    public function testOneLinePerDeliveredEvent(): void
    {
        $report = new DeliveryReport([
            new DeliveredEvent(12, 'monitoring.check_failed'),
            new DeliveredEvent(13, 'remediation.incident_opened'),
        ], [], false);

        $this->assertSame([
            'Delivered event 12 (monitoring.check_failed).',
            'Delivered event 13 (remediation.incident_opened).',
        ], TickReport::lines($report));
    }

    public function testSaysWhenTheLimitWasReached(): void
    {
        $report = new DeliveryReport(
            [new DeliveredEvent(1, 'monitoring.check_failed')],
            [new FailedDelivery(2, 'monitoring.check_failed', new \RuntimeException('A bug.'), 3, true)],
            true,
        );

        $this->assertSame(
            'Stopped after 2 events; the rest are delivered on the next tick.',
            TickReport::lines($report)[1],
        );
    }

    public function testOneErrorLinePerFailure(): void
    {
        $report = new DeliveryReport([], [
            new FailedDelivery(7, 'monitoring.check_failed', new \RuntimeException("A bug\non two lines."), 3, true),
            new FailedDelivery(9, 'monitoring.check_passed', new \LogicException('Another bug.'), 1, false),
        ], false);

        $this->assertSame([
            'The event 7 (monitoring.check_failed) failed: RuntimeException: A bug on two lines. It failed on 3 ticks and is now skipped.',
            'The event 9 (monitoring.check_passed) failed: LogicException: Another bug. '
                . 'Delivery stops here until the next tick (failed 1 of 3 times before it is skipped).',
        ], TickReport::errorLines($report));
    }

    public function testOneLinePerIncidentTheTickResolvedOrReconciled(): void
    {
        $resolved = Incident::open(new IncidentKey(1, 'heartbeat_age', ''), 100)->withId(3)->passed(200)->resolveIfDue(500);
        $opened = Incident::open(new IncidentKey(1, 'local_certificate', 'example.com'), 100)->withId(4);
        $stale = Incident::open(new IncidentKey(9, 'disk_size', ''), 100)->withId(2)->reconcile(null, 600);
        $this->assertNotNull($resolved);

        $this->assertSame([
            'Resolved incident 3 on my-project/us-east1-b/web (heartbeat_age): it passed for 5 minutes.',
            'Reconciliation opened incident 4 on my-project/us-east1-b/web (local_certificate example.com): its check is failing but no incident was open.',
            'Reconciliation resolved incident 2 on instance 9 (disk_size): its check is no longer failing.',
        ], TickReport::incidentLines([$resolved], new Reconciliation(600, [$opened], [$stale]), [1 => 'my-project/us-east1-b/web']));
        $this->assertSame([], TickReport::incidentLines([], null, []));
        $this->assertSame([], TickReport::incidentLines([], new Reconciliation(600, [], []), []));
    }
}
