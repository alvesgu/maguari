<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation\Event;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\Resolution;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testIncidentOpenedRoundTrips(): void
    {
        $incident = Incident::open(new IncidentKey(7, 'local_certificate', 'example.com'), 100)->withId(3);

        $event = IncidentOpened::of($incident, 'Expired on 2026-09-30.')->toEvent();

        $this->assertSame('remediation.incident_opened', $event->type);
        $this->assertSame(['incident_id' => 3, 'instance_id' => 7, 'check_name' => 'local_certificate', 'subject' => 'example.com', 'detail' => 'Expired on 2026-09-30.', 'detected_late' => false], $event->payload);
        $this->assertSame(100, $event->occurredAt);
        $this->assertEquals(new IncidentOpened(3, 7, 'local_certificate', 'example.com', 100, 'Expired on 2026-09-30.'), IncidentOpened::fromEvent($event));
    }

    public function testIncidentOpenedSaysWhenItWasDetectedLate(): void
    {
        $incident = Incident::open(new IncidentKey(7, 'disk_size', ''), 100)->withId(3);
        $late = IncidentOpened::of($incident, 'Grown.', detectedLate: true);

        $this->assertTrue(IncidentOpened::fromEvent($late->toEvent())->detectedLate);
        // Written before the flag existed.
        $old = new Event('remediation.incident_opened', ['incident_id' => 3, 'instance_id' => 7, 'check_name' => 'disk_size', 'subject' => '', 'detail' => 'Grown.'], 100);
        $this->assertFalse(IncidentOpened::fromEvent($old)->detectedLate);
    }

    public function testIncidentResolvedRoundTrips(): void
    {
        $incident = Incident::open(new IncidentKey(7, 'heartbeat_age', ''), 100)->withId(3)->withdraw(160);

        $event = IncidentResolved::of($incident)->toEvent();

        $this->assertSame(['incident_id' => 3, 'instance_id' => 7, 'check_name' => 'heartbeat_age', 'subject' => '', 'resolution' => 'withdrawn'], $event->payload);
        $this->assertSame(160, $event->occurredAt);
        $this->assertEquals(new IncidentResolved(3, 7, 'heartbeat_age', '', 160, Resolution::Withdrawn), IncidentResolved::fromEvent($event));
    }

    public function testAnUnstoredOrOpenIncidentHasNoEvent(): void
    {
        $this->expectException(\LogicException::class);

        IncidentResolved::of(Incident::open(new IncidentKey(7, 'heartbeat_age', ''), 100)->withId(3));
    }

    public function testAnUnknownResolutionIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        IncidentResolved::fromEvent(new Event('remediation.incident_resolved', [
            'incident_id' => 3, 'instance_id' => 7, 'check_name' => 'heartbeat_age', 'subject' => '', 'resolution' => 'forgotten',
        ], 100));
    }
}
