<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Application;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailureKind;
use Maguari\Server\Monitoring\Application\HttpChecks;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Remote HTTP checks per instance and their per-minute run (design section
 * 6.2.1), with the fake probe. The fake sleep advances the clock.
 */
final class HttpChecksTest extends TestCase
{
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
     * @return list<array{string, string}> type and payload of every event written
     */
    private function events(): array
    {
        return array_map(
            static fn (array $row): array => [$row['type'], $row['payload']],
            $this->environment->database->pdo()->query('SELECT type, payload FROM kernel_events ORDER BY id')->fetchAll(),
        );
    }

    /**
     * @param CheckResult[] $results
     * @return list<array{string, string, string}>
     */
    private static function summary(array $results): array
    {
        return array_map(static fn (CheckResult $result): array => [$result->subject, $result->outcome->value, $result->detail], $results);
    }

    public function testAddsChecksDueAtOnceAndRefusesDuplicatesAndTheEleventh(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $now = $this->environment->clock->now();

        $check = $monitoring->addHttpCheck($web->id, 'HTTPS://WWW.Example.com', '');

        $this->assertSame(['https://www.example.com/', 600, $now], [$check->url, $check->intervalSeconds, $check->nextDueAt]);
        $this->assertEquals([$check], $monitoring->httpChecks($web->id));

        try {
            $monitoring->addHttpCheck($web->id, 'https://www.example.com/', '5');
            $this->fail('A duplicate was added.');
        } catch (InvalidHttpCheck $invalid) {
            $this->assertSame('https://www.example.com/ is already checked for this instance.', $invalid->getMessage());
        }

        for ($i = 2; $i <= 10; $i++) {
            $monitoring->addHttpCheck($web->id, "https://app{$i}.example.com/", '60');
        }

        $this->expectExceptionMessage('An instance can have at most 10 web app checks.');
        $monitoring->addHttpCheck($web->id, 'https://app11.example.com/', '60');
    }

    public function testTheEgressBudgetCoversEveryInstance(): void
    {
        $web = $this->pick('web');
        $db = $this->pick('db');
        $monitoring = $this->environment->monitoring;

        // 2 minutes are 91 MB a month and 10 minutes 18 MB: a second at 2 minutes is over.
        $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '2');
        $monitoring->addHttpCheck($db->id, 'https://b.example.com/', '10');
        $this->assertSame(110, (int) round($monitoring->httpCheckMonthlyEgressBytes() / 1_000_000));

        try {
            $monitoring->addHttpCheck($db->id, 'https://c.example.com/', '2');
            $this->fail('A check over the budget was added.');
        } catch (InvalidHttpCheck $invalid) {
            $this->assertSame(
                'All web app checks together would send about 202 MB a month, above the 200 MB set aside for them. Use a longer interval or remove a check.',
                $invalid->getMessage(),
            );
        }

        $this->assertCount(1, $monitoring->httpChecks($db->id));
    }

    public function testChangingTheIntervalChecksTheBudgetOnlyWhenShorter(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $a = $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '10');
        $b = $monitoring->addHttpCheck($web->id, 'https://b.example.com/', '10');
        $monitoring->runDueHttpChecks();
        $now = $this->environment->clock->now();

        $this->assertTrue($monitoring->changeHttpCheckInterval($web->id, $a->id, '2'));
        $this->assertSame([120, $now + 120], [$monitoring->httpChecks($web->id)[0]->intervalSeconds, $monitoring->httpChecks($web->id)[0]->nextDueAt], 'Brought forward.');

        try {
            $monitoring->changeHttpCheckInterval($web->id, $b->id, '1');
            $this->fail('A change over the budget was saved.');
        } catch (InvalidHttpCheck $invalid) {
            $this->assertStringStartsWith('All web app checks together would send about 275 MB a month', $invalid->getMessage());
        }

        $this->assertTrue($monitoring->changeHttpCheckInterval($web->id, $b->id, '60'));
        $this->assertSame($now + 600, $monitoring->httpChecks($web->id)[1]->nextDueAt, 'A longer interval keeps the next check.');
        $this->assertFalse($monitoring->changeHttpCheckInterval($this->pick('db')->id, $b->id, '5'), 'Another instance\'s check.');
        $this->expectExceptionMessage('Enter an interval of 1 to 60 minutes.');
        $monitoring->changeHttpCheckInterval($web->id, $b->id, '0');
    }

    public function testARunChecksWhatIsDueAndKeepsTheSchedule(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $probe = $this->environment->httpProbe;
        $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '10');
        $monitoring->addHttpCheck($web->id, 'https://b.example.com/', '2');
        $start = $this->environment->clock->now();

        $this->assertSame([
            ['https://a.example.com/', 'pass', 'Answered 200 in 42 ms.'],
            ['https://b.example.com/', 'pass', 'Answered 200 in 42 ms.'],
        ], self::summary($monitoring->runDueHttpChecks()));
        $this->assertSame(['https://a.example.com/', HttpChecks::USER_AGENT, 5.0, 10.0], $probe->requests[0]);
        $this->assertSame([], $monitoring->runDueHttpChecks(), 'Nothing due again within the minute.');

        $this->environment->clock->advance(120);
        $this->assertSame([['https://b.example.com/', 'pass', 'Answered 200 in 42 ms.']], self::summary($monitoring->runDueHttpChecks()));

        $this->environment->clock->advance(600 - 120 + 5);
        $this->assertCount(2, $monitoring->runDueHttpChecks(), 'Both due again, a little late.');
        $this->assertSame($start + 1200, $monitoring->httpChecks($web->id)[0]->nextDueAt, 'On the schedule, not from the late run.');
        $this->assertSame(['monitoring.check_passed', 'monitoring.check_passed'], array_column($this->events(), 0), 'Only the first passes are changes.');
    }

    public function testAFailureIsRetriedOnceAndOnlyTheSecondAnswerCounts(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $probe = $this->environment->httpProbe;
        $probe->answer('https://flaky.example.com/', HttpProbeFailureKind::TimedOut, 200);
        $probe->answer('https://down.example.com/', 503);
        $monitoring->addHttpCheck($web->id, 'https://down.example.com/', '');
        $monitoring->addHttpCheck($web->id, 'https://flaky.example.com/', '');
        $start = $this->environment->clock->now();

        $results = $monitoring->runDueHttpChecks();

        $this->assertSame([
            ['https://down.example.com/', 'fail', 'Answered 503 in 42 ms. Tried twice, 5 seconds apart.'],
            ['https://flaky.example.com/', 'pass', 'Answered 200 in 42 ms.'],
        ], self::summary($results));
        $this->assertSame([5, 5], $this->environment->sleeps);
        $this->assertSame($start + 5, $results[0]->checkedAt, 'The time of the answer that counts.');
        $this->assertSame(['https://down.example.com/', 'https://down.example.com/', 'https://flaky.example.com/', 'https://flaky.example.com/'], $probe->urls());
        $events = $this->events();
        $this->assertSame(['monitoring.check_failed', 'monitoring.check_passed'], array_column($events, 0));
        $this->assertStringContainsString('"subject":"https://down.example.com/"', $events[0][1]);
        $this->assertStringContainsString('"check_name":"remote_http"', $events[0][1]);
    }

    public function testStatusesShowNotCheckedUntilTheFirstResult(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $this->environment->httpProbe->answer('https://b.example.com/', HttpProbeFailureKind::Refused);
        $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '');

        $before = $monitoring->httpCheckStatuses([$web->id, 999]);
        $this->assertSame([$web->id], array_keys($before), 'Only instances with checks.');
        $this->assertSame([CheckOutcome::NotChecked, 'Not checked yet.'], [$before[$web->id][0]->outcome(), $before[$web->id][0]->detail()]);

        $monitoring->runDueHttpChecks();
        $monitoring->addHttpCheck($web->id, 'https://b.example.com/', '');
        $monitoring->runDueHttpChecks();
        $after = $monitoring->httpCheckStatuses([$web->id])[$web->id];

        $this->assertSame([CheckOutcome::Pass, CheckOutcome::Fail], [$after[0]->outcome(), $after[1]->outcome()]);
        $this->assertSame($this->environment->clock->now(), $after[1]->state?->checkedAt);
        $this->assertSame('b.example.com refused the connection on port 443. Tried twice, 5 seconds apart.', $after[1]->detail());
    }

    public function testRemovingACheckWithdrawsItsOutcome(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $this->environment->httpProbe->answer('https://a.example.com/', 500);
        $check = $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '');
        $monitoring->runDueHttpChecks();

        $this->assertFalse($monitoring->removeHttpCheck($this->pick('db')->id, $check->id), 'Another instance\'s check.');
        $this->assertTrue($monitoring->removeHttpCheck($web->id, $check->id));
        $this->assertFalse($monitoring->removeHttpCheck($web->id, $check->id), 'Already gone.');

        $this->assertSame([], $monitoring->httpChecks($web->id));
        $this->assertSame(['monitoring.check_failed', 'monitoring.check_withdrawn'], array_column($this->events(), 0));
    }

    public function testAResultForACheckRemovedWhileItRanIsDropped(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $check = $monitoring->addHttpCheck($web->id, 'https://a.example.com/', '');
        $this->environment->httpProbe->during = static function () use ($monitoring, $web, $check): void {
            $monitoring->removeHttpCheck($web->id, $check->id);
        };

        $this->assertSame([], $monitoring->runDueHttpChecks());
        $this->assertSame([], $this->events(), 'No outcome was recorded, so nothing was withdrawn either.');
    }

    public function testNoNewCheckStartsAfterThirtySeconds(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $this->environment->httpProbe->takesSeconds = 10;

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $monitoring->addHttpCheck($web->id, "https://{$name}.example.com/", '');
        }

        $this->assertSame(['https://a.example.com/', 'https://b.example.com/', 'https://c.example.com/'], array_column(self::summary($monitoring->runDueHttpChecks()), 0));
        $this->assertSame([['https://d.example.com/', 'pass', 'Answered 200 in 42 ms.']], self::summary($monitoring->runDueHttpChecks()), 'The rest on the next run.');
    }

    public function testChecksOfInstancesNoLongerPickedAreSkipped(): void
    {
        $now = $this->environment->clock->now();
        $this->environment->database->pdo()->exec(
            "INSERT INTO monitoring_http_checks (instance_id, url, interval_seconds, added_at, next_due_at) VALUES (99, 'https://gone.example.com/', 600, {$now}, {$now})",
        );

        $this->assertSame([], $this->environment->monitoring->runDueHttpChecks());
        $this->assertSame([], $this->environment->httpProbe->requests);
        $this->assertSame($now + 600, (int) $this->environment->database->pdo()->query('SELECT next_due_at FROM monitoring_http_checks')->fetchColumn());
    }
}
