<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Application;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\Readings;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The client's checks on the server side (design section 6.1.3): defining
 * them, and judging the results heartbeats carry, a failure counting only
 * as the second in a row (design section 6.3 item 7).
 */
final class ClientChecksTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * One heartbeat of instance 7 with these service results.
     *
     * @param array<string, string> $states ActiveState by unit; "missing" for not found, "" for systemctl failing
     */
    private function heartbeat(array $states): void
    {
        $checks = [];

        foreach ($states as $unit => $state) {
            $checks[] = match ($state) {
                'active' => ['type' => 'service', 'subject' => $unit, 'outcome' => 'pass', 'state' => 'active'],
                'missing' => ['type' => 'service', 'subject' => $unit, 'outcome' => 'fail', 'reason' => 'not_found'],
                '' => ['type' => 'service', 'subject' => $unit, 'outcome' => 'not_checked', 'reason' => 'systemctl_failed'],
                default => ['type' => 'service', 'subject' => $unit, 'outcome' => 'fail', 'reason' => 'inactive', 'state' => $state],
            };
        }

        $monitoring = $this->environment->monitoring;
        $monitoring->recordHeartbeat(7, $this->environment->clock->now(), Readings::parse([]), $monitoring->parseCheckResults($checks));
        $this->environment->clock->advance(60);
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return $this->environment->database->pdo()->query('SELECT type FROM kernel_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function outcome(): CheckOutcome
    {
        return $this->environment->monitoring->clientCheckStatuses([7])[7][0]->outcome();
    }

    public function testAddsServicesAndRefusesDuplicatesAndTheTwentyFirst(): void
    {
        $monitoring = $this->environment->monitoring;
        $check = $monitoring->addServiceCheck(7, 'nginx');

        $this->assertSame(['service', 'nginx.service', ''], [$check->type, $check->target, $check->hostName]);
        $this->assertEquals([$check], $monitoring->clientChecks(7));

        try {
            $monitoring->addServiceCheck(7, 'nginx.service');
            $this->fail('A duplicate was added.');
        } catch (InvalidClientCheck $invalid) {
            $this->assertSame('nginx.service is already checked on this instance.', $invalid->getMessage());
        }

        $monitoring->addServiceCheck(8, 'nginx');

        for ($i = 2; $i <= 20; $i++) {
            $monitoring->addServiceCheck(7, "app{$i}");
        }

        $this->expectExceptionMessage('An instance can have at most 20 service checks.');
        $monitoring->addServiceCheck(7, 'app21');
    }

    public function testAFailureCountsOnlyAsTheSecondInARow(): void
    {
        $this->environment->monitoring->addServiceCheck(7, 'nginx');

        $this->heartbeat(['nginx.service' => 'failed']);
        $this->assertSame(CheckOutcome::NotChecked, $this->outcome(), 'A first failure does not count, even for a new check.');
        $this->assertStringEndsWith('The latest result failed; it counts after 2 in a row.', $this->environment->monitoring->clientCheckStatuses([7])[7][0]->detail());

        $this->heartbeat(['nginx.service' => 'active']);
        $this->heartbeat(['nginx.service' => 'failed']);
        $this->assertSame(CheckOutcome::Pass, $this->outcome(), 'The pass ended the run.');

        $this->heartbeat(['nginx.service' => '']);
        $this->assertSame(CheckOutcome::Pass, $this->outcome(), '"Not checked" neither counts nor ends the run.');

        $this->heartbeat(['nginx.service' => 'missing']);
        $this->assertSame(CheckOutcome::Fail, $this->outcome());
        $this->assertSame('There is no unit nginx.service on the instance.', $this->environment->monitoring->clientCheckStatuses([7])[7][0]->detail());

        $this->heartbeat(['nginx.service' => 'active']);
        $this->assertSame(CheckOutcome::Pass, $this->outcome(), 'A pass counts at once.');
        $this->assertSame(['monitoring.check_passed', 'monitoring.check_failed', 'monitoring.check_passed'], $this->events());
    }

    public function testResultsOfChecksTheInstanceDoesNotHaveAreIgnored(): void
    {
        $this->environment->monitoring->addServiceCheck(8, 'nginx');

        $this->heartbeat(['nginx.service' => 'active']);

        $this->assertSame([], $this->events());
        $this->assertSame(CheckOutcome::NotChecked, $this->environment->monitoring->clientCheckStatuses([8])[8][0]->outcome(), 'Another instance\'s result.');
    }

    public function testRemovingACheckWithdrawsItsOutcomeAndItsFailures(): void
    {
        $monitoring = $this->environment->monitoring;
        $check = $monitoring->addServiceCheck(7, 'nginx');
        $this->heartbeat(['nginx.service' => 'failed']);
        $this->heartbeat(['nginx.service' => 'failed']);

        $this->assertFalse($monitoring->removeClientCheck(8, $check->id), 'Another instance\'s check.');
        $this->assertTrue($monitoring->removeClientCheck(7, $check->id));

        $this->assertSame([], $monitoring->clientChecks(7));
        $this->assertSame(['monitoring.check_failed', 'monitoring.check_withdrawn'], $this->events());

        // Added back, it starts from nothing: one failure does not count.
        $monitoring->addServiceCheck(7, 'nginx');
        $this->heartbeat(['nginx.service' => 'failed']);
        $this->assertSame(CheckOutcome::NotChecked, $this->outcome());
    }

    public function testTheSettingsChangeWithTheChecks(): void
    {
        $monitoring = $this->environment->monitoring;
        $empty = $monitoring->clientCheckSettings(7)->version;
        $check = $monitoring->addServiceCheck(7, 'nginx');

        $this->assertNotSame($empty, $monitoring->clientCheckSettings(7)->version);
        $this->assertSame([['type' => 'service', 'unit' => 'nginx.service']], $monitoring->clientCheckSettings(7)->checks);
        $monitoring->removeClientCheck(7, $check->id);
        $this->assertSame($empty, $monitoring->clientCheckSettings(7)->version);
    }
}
