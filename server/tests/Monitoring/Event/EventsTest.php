<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Event;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Monitoring\Event\CheckPassed;
use Maguari\Server\Monitoring\Event\CheckWithdrawn;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testCheckFailedCarriesTheResult(): void
    {
        $result = new CheckResult(7, 'remote_certificate', CheckOutcome::Fail, 'Expired on 2026-09-30.', 100, 'example.com');

        $event = CheckFailed::fromResult($result)->toEvent();

        $this->assertSame('monitoring.check_failed', $event->type);
        $this->assertSame(['instance_id' => 7, 'check_name' => 'remote_certificate', 'subject' => 'example.com', 'detail' => 'Expired on 2026-09-30.'], $event->payload);
        $this->assertSame(100, $event->occurredAt);
        $this->assertEquals(new CheckFailed(7, 'remote_certificate', 'example.com', 100, 'Expired on 2026-09-30.'), CheckFailed::fromEvent($event));
    }

    public function testCheckPassedRoundTrips(): void
    {
        $passed = new CheckPassed(7, 'heartbeat_age', '', 100, 'The last heartbeat arrived at 2026-10-13 12:00:00 UTC.');

        $this->assertSame('monitoring.check_passed', $passed->toEvent()->type);
        $this->assertEquals($passed, CheckPassed::fromEvent($passed->toEvent()));
    }

    public function testCheckWithdrawnRoundTrips(): void
    {
        $withdrawn = new CheckWithdrawn(7, 'local_certificate', 'example.com', 100);

        $this->assertSame(['instance_id' => 7, 'check_name' => 'local_certificate', 'subject' => 'example.com'], $withdrawn->toEvent()->payload);
        $this->assertEquals($withdrawn, CheckWithdrawn::fromEvent($withdrawn->toEvent()));
    }

    public function testReadingAnotherTypeIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CheckPassed::fromEvent((new CheckFailed(7, 'disk_size', '', 100, 'A sentence.'))->toEvent());
    }

    public function testAMalformedPayloadIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CheckWithdrawn::fromEvent(new Event('monitoring.check_withdrawn', ['instance_id' => '7', 'check_name' => 'disk_size', 'subject' => ''], 100));
    }
}
