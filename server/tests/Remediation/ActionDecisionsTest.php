<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation;

use Maguari\Server\Remediation\Domain\Ladder;
use Maguari\Server\Remediation\Domain\Mode;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The tick's ladder step from real check results to recorded actions, in the
 * order the tick runs them (design sections 7.1 and 10.1).
 */
final class ActionDecisionsTest extends TestCase
{
    private const NGINX_FAILED = ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'failed'];
    private const LOCAL_URL = 'http://127.0.0.1/health';

    private TestEnvironment $environment;
    private int $instanceId;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => 'web', 'zone' => $zoneUrl, 'status' => 'RUNNING']);
        $this->instanceId = $this->environment->fleet->pickInstance(1, 'us-east1-b', 'web')->id;
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function heartbeat(array $checks): void
    {
        $monitoring = $this->environment->monitoring;
        $monitoring->recordHeartbeat($this->instanceId, $this->environment->clock->now(), $monitoring->parseReadings([]), $monitoring->parseCheckResults($checks));
    }

    /**
     * What the tick does up to its ladder step.
     *
     * @return list<string> the sentences of the actions decided
     */
    private function tick(): array
    {
        $this->environment->eventDelivery()->deliver();
        $this->environment->remediation->resolveDue();

        return array_map(static fn ($action): string => $action->sentence(), $this->environment->remediation->decideActions());
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function failTwice(array $checks): void
    {
        $this->heartbeat($checks);
        $this->environment->clock->advance(60);
        $this->heartbeat($checks);
        $this->tick();
    }

    private function automatic(): void
    {
        $this->environment->remediation->setMode($this->instanceId, Mode::Automatic);
    }

    private function advanceWithHeartbeat(int $seconds, array $checks): void
    {
        $this->environment->clock->advance($seconds);
        $this->heartbeat($checks);
    }

    public function testAnInstanceStartsInAlertOnlyAndDecidesNothing(): void
    {
        $this->failTwice([self::NGINX_FAILED]);
        $this->advanceWithHeartbeat(600, [self::NGINX_FAILED]);

        $this->assertSame(Mode::AlertOnly, $this->environment->remediation->instanceRemediation($this->instanceId)->mode);
        $this->assertSame([], $this->tick());
        $this->assertSame([], $this->environment->remediation->actionsOf($this->instanceId));
    }

    public function testTheLadderClimbsAsDryRunFromRealResults(): void
    {
        $this->automatic();
        $this->environment->clock->advance(180);
        $this->failTwice([self::NGINX_FAILED]);

        $this->advanceWithHeartbeat(120, [self::NGINX_FAILED]);
        $this->assertSame([], $this->tick(), 'Not 3 minutes yet.');

        $this->advanceWithHeartbeat(60, [self::NGINX_FAILED]);
        $this->assertSame(['I would restart nginx.service now.'], $this->tick());
        $this->assertSame(Ladder::STEP_RESTART, $this->environment->remediation->incidents($this->instanceId)->open[0]->ladderStep);

        // No result newer than the action yet: the same second's result does not count.
        $this->assertSame([], $this->tick());

        // A repeated failure writes no event, and its newer time still moves the ladder.
        $this->advanceWithHeartbeat(60, [self::NGINX_FAILED]);
        $this->assertSame(['I would restart nginx.service now.'], $this->tick());

        $this->advanceWithHeartbeat(60, [self::NGINX_FAILED]);
        $this->assertSame(['I would reboot the instance now.'], $this->tick());

        $this->advanceWithHeartbeat(419, [self::NGINX_FAILED]);
        $this->assertSame([], $this->tick(), 'Boot grace period.');

        $this->advanceWithHeartbeat(1, [self::NGINX_FAILED]);
        $this->assertSame(['I would reset the instance now.'], $this->tick());

        $this->advanceWithHeartbeat(420, [self::NGINX_FAILED]);
        $this->assertSame([], $this->tick());
        $incident = $this->environment->remediation->incidents($this->instanceId)->open[0];
        $this->assertSame(Ladder::STEP_RESET, $incident->ladderStep);
        $this->assertSame($this->environment->clock->now(), $incident->exhaustedAt);

        $actions = $this->environment->remediation->actionsOf($this->instanceId);
        $this->assertCount(4, $actions);
        $this->assertTrue($actions[0]->dryRun);
        $this->assertSame([$incident->id], $actions[0]->incidentIds);
    }

    public function testAPassStopsTheLadder(): void
    {
        $this->automatic();
        $this->environment->clock->advance(180);
        $this->failTwice([self::NGINX_FAILED]);
        $this->advanceWithHeartbeat(180, [self::NGINX_FAILED]);
        $this->assertCount(1, $this->tick());

        $this->advanceWithHeartbeat(60, [['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active']]);
        $this->assertSame([], $this->tick());
    }

    public function testALateHeartbeatStopsRestartsOnStaleResults(): void
    {
        $this->automatic();
        $this->environment->clock->advance(180);
        $this->failTwice([self::NGINX_FAILED]);
        $this->environment->clock->advance(180);

        $this->assertSame([], $this->tick());
    }

    public function testTwoChecksOnOneUnitGetOneRestart(): void
    {
        $remediation = $this->environment->remediation;
        $check = $this->environment->monitoring->addLocalHttpCheck($this->instanceId, self::LOCAL_URL, '');
        $remediation->setCheckUnit($this->instanceId, $check->id, 'nginx');
        $this->assertSame('nginx.service', $remediation->checkUnits($this->instanceId)[$remediation::checkUnitKey('local_http', self::LOCAL_URL)]);
        $this->automatic();
        $this->environment->clock->advance(180);
        $checks = [self::NGINX_FAILED, ['type' => 'local_http', 'subject' => self::LOCAL_URL, 'outcome' => 'fail', 'reason' => 'refused']];
        $this->failTwice($checks);
        $this->advanceWithHeartbeat(180, $checks);

        $this->assertSame(['I would restart nginx.service now.'], $this->tick());
        $this->assertCount(2, $remediation->actionsOf($this->instanceId)[0]->incidentIds);

        foreach ($remediation->incidents($this->instanceId)->open as $incident) {
            $this->assertSame(Ladder::STEP_RESTART, $incident->ladderStep);
        }
    }

    public function testALocalCheckWithoutAUnitOnlyAlerts(): void
    {
        $this->environment->monitoring->addLocalHttpCheck($this->instanceId, self::LOCAL_URL, '');
        $this->automatic();
        $this->environment->clock->advance(180);
        $checks = [['type' => 'local_http', 'subject' => self::LOCAL_URL, 'outcome' => 'fail', 'reason' => 'refused']];
        $this->failTwice($checks);
        $this->advanceWithHeartbeat(180, $checks);

        $this->assertSame([], $this->tick());
    }

    public function testNeverActsOnNotFoundAndOnARefusedLoginButResumesWhenTheReasonChanges(): void
    {
        $remediation = $this->environment->remediation;
        $check = $this->environment->monitoring->addDatabaseCheck($this->instanceId, 'geonode');
        $remediation->setCheckUnit($this->instanceId, $check->id, 'postgresql');
        $this->automatic();
        $this->environment->clock->advance(180);
        $refused = [
            ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'not_found'],
            ['type' => 'database', 'subject' => 'geonode', 'outcome' => 'fail', 'reason' => 'login_refused'],
        ];
        $this->failTwice($refused);
        $this->advanceWithHeartbeat(180, $refused);
        $this->assertSame([], $this->tick());

        // The outcome stays fail, so no event says the reason changed.
        $this->advanceWithHeartbeat(60, [self::NGINX_FAILED, ['type' => 'database', 'subject' => 'geonode', 'outcome' => 'fail', 'reason' => 'unreachable']]);
        $this->assertSame(['I would restart nginx.service now.', 'I would restart postgresql.service now.'], $this->tick());
    }

    public function testRemovingACheckForgetsItsUnit(): void
    {
        $remediation = $this->environment->remediation;
        $check = $this->environment->monitoring->addLocalHttpCheck($this->instanceId, self::LOCAL_URL, '');
        $remediation->setCheckUnit($this->instanceId, $check->id, 'nginx');
        $checks = [['type' => 'local_http', 'subject' => self::LOCAL_URL, 'outcome' => 'fail', 'reason' => 'refused']];
        $this->failTwice($checks);

        $this->environment->monitoring->removeClientCheck($this->instanceId, $check->id);
        $this->tick();

        $this->assertSame([], $remediation->checkUnits($this->instanceId));
    }

    public function testAnEmptyUnitRemovesTheAssociationAndAServiceCheckIgnoresIt(): void
    {
        $remediation = $this->environment->remediation;
        $check = $this->environment->monitoring->addLocalHttpCheck($this->instanceId, self::LOCAL_URL, '');
        $remediation->setCheckUnit($this->instanceId, $check->id, 'nginx');
        $remediation->setCheckUnit($this->instanceId, $check->id, ' ');
        $this->assertSame([], $remediation->checkUnits($this->instanceId));


        foreach ($this->environment->monitoring->clientCheckStatuses([$this->instanceId])[$this->instanceId] as $status) {
            if ($status->check->type === 'service') {
                $remediation->setCheckUnit($this->instanceId, $status->check->id, 'other');
            }
        }

        $this->assertSame([], $remediation->checkUnits($this->instanceId));
    }

    public function testAMaintenanceEndsByItselfAndTheModeComesBack(): void
    {
        $remediation = $this->environment->remediation;
        $this->automatic();
        $until = $this->environment->clock->now() + 600;
        $remediation->startMaintenance($this->instanceId, gmdate('Y-m-d\TH:i', $until));
        $end = $remediation->instanceRemediation($this->instanceId)->maintenanceUntil;
        $this->failTwice([self::NGINX_FAILED]);

        $this->advanceWithHeartbeat(300, [self::NGINX_FAILED]);
        $this->assertTrue($remediation->isInMaintenance($this->instanceId));
        $this->assertSame([], $this->tick());

        $this->environment->clock->advance((int) $end + 179 - $this->environment->clock->now());
        $this->heartbeat([self::NGINX_FAILED]);
        $this->assertFalse($remediation->isInMaintenance($this->instanceId));
        $this->assertSame([], $this->tick(), 'The wait starts over.');

        $this->environment->clock->advance(1);
        $this->heartbeat([self::NGINX_FAILED]);
        $this->assertSame(['I would restart nginx.service now.'], $this->tick());
        $this->assertSame(Mode::Automatic, $remediation->instanceRemediation($this->instanceId)->mode);
    }
}
