<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Application;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The heartbeat-age check judged by the tick (design section 6.2) and the
 * events Monitoring writes when a check's outcome changes (section 6.3).
 */
final class CheckOutcomeEventsTest extends TestCase
{
    private const DAY = 86_400;

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function pick(string $name): Instance
    {
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => $name, 'zone' => $zoneUrl, 'status' => 'RUNNING']);

        return $this->environment->fleet->pickInstance(1, 'us-east1-b', $name);
    }

    /**
     * @param list<array{metric: string, value: int}> $readings
     */
    private function heartbeat(int $instanceId, array $readings = []): void
    {
        $monitoring = $this->environment->monitoring;
        $monitoring->recordHeartbeat($instanceId, $this->environment->clock->now(), $monitoring->parseReadings($readings));
    }

    /**
     * @return list<CheckOutcome> the tick's outcomes
     */
    private function tick(): array
    {
        return array_map(static fn ($result): CheckOutcome => $result->outcome, $this->environment->monitoring->judgeHeartbeatAges());
    }

    /**
     * Advances a minute and ticks, $times times.
     *
     * @return list<CheckOutcome> the last tick's outcomes
     */
    private function ticksEveryMinute(int $times): array
    {
        $outcomes = [];

        for ($i = 0; $i < $times; $i++) {
            $this->environment->clock->advance(60);
            $outcomes = $this->tick();
        }

        return $outcomes;
    }

    private function runDailyJob(): void
    {
        $this->environment->http->queueJson(200, ['items' => []]);
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);
    }

    /**
     * @return list<array{string, array<string, mixed>, int}> type, payload and time
     */
    private function events(): array
    {
        return array_map(
            static fn (array $row): array => [$row['type'], json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), (int) $row['occurred_at']],
            $this->environment->database->pdo()->query('SELECT type, payload, occurred_at FROM kernel_events ORDER BY id')->fetchAll(),
        );
    }

    /**
     * @return list<string> "type check_name subject"
     */
    private function eventSummaries(): array
    {
        return array_map(static fn (array $event): string => trim("{$event[0]} {$event[1]['check_name']} {$event[1]['subject']}"), $this->events());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function states(): array
    {
        return $this->environment->database->pdo()
            ->query('SELECT instance_id, check_name, subject, outcome, since FROM monitoring_check_states ORDER BY instance_id, check_name, subject')
            ->fetchAll();
    }

    public function testAnInstanceThatNeverSentAHeartbeatIsNotChecked(): void
    {
        $this->pick('web');

        $this->assertSame([], $this->tick());
        $this->assertSame([], $this->events());
        $this->assertSame([], $this->states());
    }

    public function testOnlyPickedInstancesAreJudged(): void
    {
        $web = $this->pick('web');
        $this->heartbeat($web->id);
        $this->heartbeat(99);

        $results = $this->environment->monitoring->judgeHeartbeatAges();

        $this->assertSame([$web->id], array_map(static fn ($result): int => $result->instanceId, $results));
    }

    public function testOutcomeChangesWriteOneEventEachAndRepeatsWriteNone(): void
    {
        $web = $this->pick('web');
        $heartbeatAt = $this->environment->clock->now();
        $this->heartbeat($web->id);

        $this->assertSame([CheckOutcome::Pass], $this->tick());
        $this->assertSame([CheckOutcome::Pass], $this->ticksEveryMinute(1), '60 seconds old.');
        $this->assertSame([CheckOutcome::Fail], $this->ticksEveryMinute(1), '120 seconds old.');
        $failedAt = $this->environment->clock->now();
        $this->assertSame([CheckOutcome::Fail], $this->ticksEveryMinute(2));
        $this->heartbeat($web->id);
        $this->assertSame([CheckOutcome::Pass], $this->ticksEveryMinute(1));

        $this->assertSame([
            'monitoring.check_passed heartbeat_age',
            'monitoring.check_failed heartbeat_age',
            'monitoring.check_passed heartbeat_age',
        ], $this->eventSummaries());
        $failed = $this->events()[1];
        $this->assertSame([
            'instance_id' => $web->id,
            'check_name' => 'heartbeat_age',
            'subject' => '',
            'detail' => 'No heartbeat since ' . gmdate('Y-m-d H:i:s', $heartbeatAt) . ' UTC. Heartbeats are expected every 60 seconds and are late after 90.',
        ], $failed[1]);
        $this->assertSame($failedAt, $failed[2]);
        $this->assertSame(
            [['instance_id' => $web->id, 'check_name' => 'heartbeat_age', 'subject' => '', 'outcome' => 'pass', 'since' => $this->environment->clock->now()]],
            $this->states(),
        );
    }

    public function testATickAfterAGapJudgesNothingLate(): void
    {
        $web = $this->pick('web');
        $this->heartbeat($web->id);
        $this->tick();
        // The server was off for 4 minutes, and so was the instance.
        $this->environment->clock->advance(240);

        $this->assertSame([CheckOutcome::Pass], $this->tick(), 'Ticks resume: nothing is late yet.');
        $this->assertSame([CheckOutcome::Pass], $this->ticksEveryMinute(1), '60 seconds after resuming.');
        $this->assertSame([CheckOutcome::Fail], $this->ticksEveryMinute(1), '120 seconds after resuming.');
        $this->assertSame(['monitoring.check_passed heartbeat_age', 'monitoring.check_failed heartbeat_age'], $this->eventSummaries());
    }

    public function testTheFirstTickEverJudgesNothingLate(): void
    {
        $web = $this->pick('web');
        $this->heartbeat($web->id);
        $this->environment->clock->advance(self::DAY);

        $this->assertSame([CheckOutcome::Pass], $this->tick());
        $this->assertSame([CheckOutcome::Fail], $this->ticksEveryMinute(2));
    }

    public function testDailyResultsWriteEventsOnlyWhenTheOutcomeChanges(): void
    {
        $web = $this->pick('web');
        $silent = $this->pick('silent');
        $now = $this->environment->clock->now();
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:example.com', 'value' => $now + 5 * self::DAY]]);

        $this->runDailyJob();
        $this->environment->clock->advance(3600);
        $this->runDailyJob();

        // Disk size is not checked on either instance (no readings of /):
        // no event. The certificate fails once.
        $this->assertSame(['monitoring.check_failed local_certificate example.com'], $this->eventSummaries());
        $this->assertSame($web->id, $this->events()[0][1]['instance_id']);
        $this->assertSame('Expires on ' . gmdate('Y-m-d', $now + 5 * self::DAY) . ', in 5 days. certbot renews well before expiry, so renewal is failing on this instance.', $this->events()[0][1]['detail']);
        $this->assertSame([$web->id], array_column($this->states(), 'instance_id'), 'Nothing for ' . $silent->name . '.');
    }

    public function testACertificateNoLongerReportedIsWithdrawnWhileTheInstanceReports(): void
    {
        $web = $this->pick('web');
        $now = $this->environment->clock->now();
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY]]);
        $this->tick();
        $this->runDailyJob();
        // Two days later the instance still reports, without the certificate.
        $this->environment->clock->advance(2 * self::DAY);
        $this->heartbeat($web->id);
        $this->tick();

        $this->runDailyJob();

        $this->assertSame([
            'monitoring.check_passed heartbeat_age',
            'monitoring.check_passed local_certificate example.com',
            'monitoring.check_withdrawn local_certificate example.com',
        ], $this->eventSummaries());
        $this->assertSame(['instance_id' => $web->id, 'check_name' => 'local_certificate', 'subject' => 'example.com'], $this->events()[2][1]);
        $this->assertSame(['heartbeat_age'], array_column($this->states(), 'check_name'));
    }

    public function testACertificateIsNotWithdrawnWhileTheInstanceIsSilent(): void
    {
        $web = $this->pick('web');
        $now = $this->environment->clock->now();
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY]]);
        $this->tick();
        $this->runDailyJob();
        // Two days later the instance is silent, and ticks have run long enough to tell.
        $this->environment->clock->advance(2 * self::DAY);
        $this->tick();
        $this->assertSame([CheckOutcome::Fail], $this->ticksEveryMinute(2));

        $this->runDailyJob();

        $this->assertNotContains('monitoring.check_withdrawn local_certificate example.com', $this->eventSummaries());
        $this->assertContains('local_certificate', array_column($this->states(), 'check_name'));
    }

    public function testACertificateIsNotWithdrawnWithoutAHeartbeatAgeOutcome(): void
    {
        $web = $this->pick('web');
        $now = $this->environment->clock->now();
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY]]);
        $this->runDailyJob();
        $this->environment->clock->advance(2 * self::DAY);

        // No tick ever judged the instance, so nobody knows it is reporting.
        $this->runDailyJob();

        $this->assertSame(['monitoring.check_passed local_certificate example.com'], $this->eventSummaries());
    }

    public function testAFailedRunChangesNoOutcomeAndWithdrawsNothing(): void
    {
        $web = $this->pick('web');
        $now = $this->environment->clock->now();
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY]]);
        $this->tick();
        $this->runDailyJob();
        $this->environment->clock->advance(2 * self::DAY);
        $this->heartbeat($web->id, [['metric' => 'certificate_expires_at:other.example.com', 'value' => $now + 5 * self::DAY]]);
        $this->tick();
        // Storing the results fails.
        $this->environment->database->pdo()->exec('DROP TABLE monitoring_check_results');

        try {
            $this->runDailyJob();
            $this->fail('The run did not fail.');
        } catch (DailyJobFailed) {
        }

        $this->assertSame([
            'monitoring.check_passed heartbeat_age',
            'monitoring.check_passed local_certificate example.com',
        ], $this->eventSummaries());
    }

    public function testRemovingAHostnameWithdrawsItAtOnce(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $this->environment->tls->serve('www.example.com', $this->environment->clock->now() + 60 * self::DAY);
        $checked = $monitoring->addCertificateHostname($web->id, 'www.example.com');
        $this->runDailyJob();
        $this->environment->clock->advance(60);

        // The instance never sent a heartbeat: removing is the administrator's decision.
        $this->assertTrue($monitoring->removeCertificateHostname($web->id, $checked->id));
        $this->assertFalse($monitoring->removeCertificateHostname($web->id, $checked->id));

        $this->assertSame([
            'monitoring.check_passed remote_certificate www.example.com',
            'monitoring.check_withdrawn remote_certificate www.example.com',
        ], $this->eventSummaries());
        $this->assertSame($this->environment->clock->now(), $this->events()[1][2]);
        $this->assertSame([], $this->states());
        $this->assertSame([], $monitoring->certificateHostnames($web->id));
    }

    public function testRemovingAHostnameWithoutAnOutcomeWritesNoEvent(): void
    {
        $web = $this->pick('web');
        $hostname = $this->environment->monitoring->addCertificateHostname($web->id, 'www.example.com');

        $this->assertTrue($this->environment->monitoring->removeCertificateHostname($web->id, $hostname->id));
        $this->assertSame([], $this->events());
    }
}
