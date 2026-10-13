<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation;

use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Monitoring\Event\CheckPassed;
use Maguari\Server\Monitoring\Event\CheckWithdrawn;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\Resolution;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Incidents opened and resolved from Monitoring's events, by the tick's
 * Remediation step and by the daily reconciliation (design section 7.4).
 */
final class IncidentsTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        // Most tests are not about reconciliation: it ran just now.
        $this->environment->database->pdo()->exec(
            'INSERT INTO remediation_reconciliations (ran_at, opened, resolved) VALUES (' . $this->environment->clock->now() . ', 0, 0)',
        );
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function publish(CheckFailed|CheckPassed|CheckWithdrawn $event): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record($event->toEvent()));
    }

    private function failed(string $checkName = 'heartbeat_age', string $subject = '', int $instanceId = 7): CheckFailed
    {
        return new CheckFailed($instanceId, $checkName, $subject, $this->environment->clock->now(), 'It failed.');
    }

    private function passed(string $checkName = 'heartbeat_age', string $subject = '', int $instanceId = 7): CheckPassed
    {
        return new CheckPassed($instanceId, $checkName, $subject, $this->environment->clock->now(), 'It passed.');
    }

    private function deliver(): void
    {
        $report = (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->remediation->subscribers()))->deliver();
        $this->assertSame([], $report->failures);
    }

    /**
     * @return list<array{string, array<string, mixed>, int}> type, payload and time of Remediation's events
     */
    private function remediationEvents(): array
    {
        return array_map(
            static fn (array $row): array => [$row['type'], json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), (int) $row['occurred_at']],
            $this->environment->database->pdo()
                ->query("SELECT type, payload, occurred_at FROM kernel_events WHERE type LIKE 'remediation.%' ORDER BY id")->fetchAll(),
        );
    }

    /**
     * @return list<Incident>
     */
    private function open(int $instanceId = 7): array
    {
        return $this->environment->remediation->incidents($instanceId)->open;
    }

    public function testAFailureOpensOneIncidentWithItsEvent(): void
    {
        $openedAt = $this->environment->clock->now();
        $this->publish($this->failed('local_certificate', 'example.com'));
        $this->environment->clock->advance(30);

        $this->deliver();

        [$incident] = $this->open();
        $this->assertSame(1, $incident->id);
        $this->assertSame('local_certificate', $incident->key->checkName);
        $this->assertSame('example.com', $incident->key->subject);
        $this->assertSame($openedAt, $incident->openedAt, 'Opened when the check failed, not when the event was delivered.');
        $this->assertSame([[
            'remediation.incident_opened',
            ['incident_id' => 1, 'instance_id' => 7, 'check_name' => 'local_certificate', 'subject' => 'example.com', 'detail' => 'It failed.'],
            $openedAt,
        ]], $this->remediationEvents());
    }

    public function testTheOpenIncidentIsUniquePerKey(): void
    {
        $this->publish($this->failed());
        $this->deliver();

        $this->expectException(\PDOException::class);

        $this->environment->database->pdo()->exec(
            "INSERT INTO remediation_incidents (instance_id, check_name, subject, opened_at) VALUES (7, 'heartbeat_age', '', 1)",
        );
    }

    public function testAResolvedIncidentDoesNotBlockANewOne(): void
    {
        $this->publish($this->failed());
        $this->deliver();
        $this->publish(new CheckWithdrawn(7, 'heartbeat_age', '', $this->environment->clock->now()));
        $this->deliver();
        $this->environment->clock->advance(60);

        $this->publish($this->failed());
        $this->deliver();

        $this->assertSame([2], array_map(static fn (Incident $incident): ?int => $incident->id, $this->open()));
    }

    public function testFailPassFailAgainAndResolveAtExactlyFiveMinutes(): void
    {
        $remediation = $this->environment->remediation;
        $clock = $this->environment->clock;
        $this->publish($this->failed());
        $this->deliver();
        $clock->advance(60);
        $this->publish($this->passed());
        $this->deliver();
        $clock->advance(120);
        // Fails again within 5 minutes: same incident, the wait starts over.
        $this->publish($this->failed());
        $this->deliver();
        $this->assertNull($this->open()[0]->passingSince);
        $clock->advance(60);
        $passingSince = $clock->now();
        $this->publish($this->passed());
        $this->deliver();

        $clock->advance(299);
        $this->assertSame([], $remediation->resolveDue());
        $clock->advance(1);
        $resolved = $remediation->resolveDue();

        $this->assertCount(1, $resolved);
        $this->assertSame($passingSince, $resolved[0]->passingSince);
        $this->assertSame(Resolution::Passed, $resolved[0]->resolution);
        $this->assertSame([], $this->open());
        $this->assertSame([1], array_map(static fn (Incident $incident): ?int => $incident->id, $remediation->incidents(7)->resolved));
        $this->assertSame(['remediation.incident_opened', 'remediation.incident_resolved'], array_column($this->remediationEvents(), 0));
        $this->assertSame([
            ['incident_id' => 1, 'instance_id' => 7, 'check_name' => 'heartbeat_age', 'subject' => '', 'resolution' => 'passed'],
            $clock->now(),
        ], array_slice($this->remediationEvents()[1], 1));
    }

    public function testAPassWithoutAnOpenIncidentDoesNothing(): void
    {
        $this->publish($this->passed());
        $this->deliver();

        $this->assertSame([], $this->open());
        $this->assertSame([], $this->remediationEvents());
    }

    public function testWithdrawnResolvesAtOnce(): void
    {
        $this->publish($this->failed('remote_certificate', 'www.example.com'));
        $this->deliver();
        $this->environment->clock->advance(60);
        $withdrawnAt = $this->environment->clock->now();
        $this->publish(new CheckWithdrawn(7, 'remote_certificate', 'www.example.com', $withdrawnAt));

        $this->deliver();

        [$resolved] = $this->environment->remediation->incidents(7)->resolved;
        $this->assertSame(Resolution::Withdrawn, $resolved->resolution);
        $this->assertSame($withdrawnAt, $resolved->resolvedAt);
        $this->assertSame('withdrawn', $this->remediationEvents()[1][1]['resolution']);
    }

    public function testTwoIncidentsOnOneInstance(): void
    {
        $this->publish($this->failed('heartbeat_age'));
        $this->publish($this->failed('local_certificate', 'example.com'));
        $this->publish($this->failed('local_certificate', 'www.example.com'));
        $this->publish($this->failed('heartbeat_age', '', 8));

        $this->deliver();

        $this->assertCount(3, $this->open(7));
        $this->assertCount(1, $this->open(8));
        $this->assertCount(4, $this->environment->remediation->openIncidents([7, 8]));
        $this->assertSame([], $this->environment->remediation->openIncidents([]));
    }

    public function testTheResolvedListIsTheLatestTwenty(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->publish($this->failed());
            $this->publish(new CheckWithdrawn(7, 'heartbeat_age', '', $this->environment->clock->now()));
            $this->environment->clock->advance(60);
        }

        $this->deliver();

        $resolved = $this->environment->remediation->incidents(7)->resolved;
        $this->assertCount(20, $resolved);
        $this->assertSame(21, $resolved[0]->id, 'Newest first.');
    }

    private function setState(int $instanceId, string $checkName, string $subject, string $outcome, int $since): void
    {
        $this->environment->database->pdo()->prepare(
            'INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since) VALUES (?, ?, ?, ?, ?) '
                . 'ON CONFLICT (instance_id, check_name, subject) DO UPDATE SET outcome = excluded.outcome, since = excluded.since',
        )->execute([$instanceId, $checkName, $subject, $outcome, $since]);
    }

    private function forgetReconciliations(): void
    {
        $this->environment->database->pdo()->exec('DELETE FROM remediation_reconciliations');
    }

    public function testReconciliationRunsAtOnceWhenItNeverRanAndThenOnceADay(): void
    {
        $remediation = $this->environment->remediation;
        $this->forgetReconciliations();

        $first = $remediation->reconcileIfDue();
        $this->assertNotNull($first);
        $this->assertSame([[$first->ranAt, 0, 0]], array_map('array_values', $this->environment->database->pdo()
            ->query('SELECT ran_at, opened, resolved FROM remediation_reconciliations')->fetchAll()));
        $this->environment->clock->advance(86_400);
        $this->assertNull($remediation->reconcileIfDue(), 'Exactly a day later is not more than a day.');
        $this->environment->clock->advance(1);
        $this->assertNotNull($remediation->reconcileIfDue());
        $this->assertNull($remediation->reconcileIfDue());
    }

    public function testReconciliationOpensAMissingIncident(): void
    {
        $since = $this->environment->clock->now() - 600;
        // A failure whose event was lost: Monitoring has it, Remediation does not.
        $this->setState(7, 'disk_size', '', 'fail', $since);
        $this->forgetReconciliations();

        $reconciliation = $this->environment->remediation->reconcileIfDue();

        $this->assertCount(1, $reconciliation?->opened ?? []);
        [$incident] = $this->open();
        $this->assertSame('disk_size', $incident->key->checkName);
        $this->assertSame($since, $incident->openedAt);
        $this->assertSame([[
            'remediation.incident_opened',
            ['incident_id' => 1, 'instance_id' => 7, 'check_name' => 'disk_size', 'subject' => '', 'detail' => ''],
            $since,
        ]], $this->remediationEvents());
    }

    public function testReconciliationResolvesStaleIncidentsAndWaitsForARecentPass(): void
    {
        $now = $this->environment->clock->now();
        $this->publish($this->failed('heartbeat_age'));
        $this->publish($this->failed('disk_size'));
        $this->publish($this->failed('local_certificate', 'example.com'));
        $this->publish($this->failed('remote_certificate', 'www.example.com'));
        $this->deliver();
        // Passing events lost: one passing long enough, one only just. One
        // is gone from Monitoring, one is still failing.
        $this->setState(7, 'heartbeat_age', '', 'pass', $now - 300);
        $this->setState(7, 'disk_size', '', 'pass', $now - 60);
        $this->setState(7, 'remote_certificate', 'www.example.com', 'fail', $now);
        $this->forgetReconciliations();

        $reconciliation = $this->environment->remediation->reconcileIfDue();

        $this->assertSame([], $reconciliation?->opened);
        $this->assertSame(
            [['heartbeat_age', Resolution::Reconciled], ['local_certificate', Resolution::Reconciled]],
            array_map(static fn (Incident $incident): array => [$incident->key->checkName, $incident->resolution], $reconciliation?->resolved ?? []),
        );
        $open = $this->open();
        $this->assertSame(['remote_certificate', 'disk_size'], array_map(static fn (Incident $incident): string => $incident->key->checkName, $open));
        $this->assertSame($now - 60, $open[1]->passingSince, 'Waits for the normal rule.');
        $this->environment->clock->advance(240);
        $this->assertSame(['disk_size'], array_map(static fn (Incident $incident): string => $incident->key->checkName, $this->environment->remediation->resolveDue()));
    }

    public function testReconciliationClearsThePassOfAnIncidentThatIsFailing(): void
    {
        $this->publish($this->failed());
        $this->publish($this->passed());
        $this->deliver();
        // The second failure's event was lost.
        $this->setState(7, 'heartbeat_age', '', 'fail', $this->environment->clock->now());
        $this->forgetReconciliations();

        $this->environment->remediation->reconcileIfDue();
        $this->environment->clock->advance(3600);

        $this->assertSame([], $this->environment->remediation->resolveDue());
        $this->assertNull($this->open()[0]->passingSince);
    }

    public function testATickFlowFromAHeartbeatThatStops(): void
    {
        $environment = $this->environment;
        $environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $environment->fleet->addProject('my-project');
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $environment->http->queueJson(200, ['id' => '1', 'name' => 'web', 'zone' => $zoneUrl, 'status' => 'RUNNING']);
        $web = $environment->fleet->pickInstance(1, 'us-east1-b', 'web');
        $heartbeat = fn () => $environment->monitoring->recordHeartbeat($web->id, $environment->clock->now(), $environment->monitoring->parseReadings([]));
        $tick = function () use ($environment): void {
            $environment->clock->advance(60);
            $environment->monitoring->judgeHeartbeatAges();
            $this->deliver();
            $environment->remediation->resolveDue();
            $this->deliver();
        };
        $heartbeat();

        // The first tick ever counts as ticks resuming, so the silent
        // instance is judged late 120 seconds after it: on the third tick.
        $tick();
        $tick();
        $this->assertSame([], $this->open($web->id));
        $tick();
        $this->assertCount(1, $this->open($web->id));

        // The client runs again: passing from the next tick on, resolved
        // 5 minutes later.
        for ($i = 0; $i < 5; $i++) {
            $heartbeat();
            $tick();
        }

        $this->assertCount(1, $this->open($web->id));
        $heartbeat();
        $tick();
        $this->assertSame([], $this->open($web->id));
        $this->assertSame(Resolution::Passed, $environment->remediation->incidents($web->id)->resolved[0]->resolution);
    }
}
