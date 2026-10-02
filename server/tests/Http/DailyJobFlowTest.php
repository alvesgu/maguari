<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Monitoring\DailyJobTrigger;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class DailyJobFlowTest extends TestCase
{
    private const GIB = 1024 ** 3;

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function signedInBrowser(): Browser
    {
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/login');
        $browser->post('/auth/login', Browser::csrfFields($form) + [
            'email' => TestEnvironment::ADMINISTRATOR_EMAIL,
            'password' => TestEnvironment::ADMINISTRATOR_PASSWORD,
        ]);

        return $browser;
    }

    private function pick(string $name): int
    {
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => $name, 'zone' => $zoneUrl, 'status' => 'RUNNING']);

        return $this->environment->fleet->pickInstance(1, 'us-east1-b', $name)->id;
    }

    /**
     * @param array<string, int> $bootDiskGb by instance name
     */
    private function queueListing(array $bootDiskGb): void
    {
        $instances = [];

        foreach ($bootDiskGb as $name => $sizeGb) {
            $instances[] = [
                'id' => '1', 'name' => $name, 'status' => 'RUNNING',
                'zone' => 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b',
                'disks' => [['deviceName' => $name, 'boot' => true, 'diskSizeGb' => (string) $sizeGb]],
            ];
        }

        $this->environment->http->queueJson(200, ['items' => ['zones/us-east1-b' => ['instances' => $instances]]]);
    }

    private function recordRootTotal(int $instanceId, int $bytes): void
    {
        $monitoring = $this->environment->monitoring;
        $monitoring->recordReadings($instanceId, $this->environment->clock->now(), $monitoring->parseReadings([
            ['metric' => 'disk_used_bytes:/', 'value' => intdiv($bytes, 2)],
            ['metric' => 'disk_total_bytes:/', 'value' => $bytes],
        ]));
    }

    private function runNow(Browser $browser): \Psr\Http\Message\ResponseInterface
    {
        return $browser->post('/admin/daily-job', Browser::csrfFields($browser->get('/admin')));
    }

    private function insertRun(string $trigger, int $startedAt, ?int $finishedAt, int $failed = 0): void
    {
        $this->environment->database->pdo()->prepare(
            'INSERT INTO monitoring_daily_job_runs (triggered_by, started_at, finished_at, failed) VALUES (?, ?, ?, ?)',
        )->execute([$trigger, $startedAt, $finishedAt, $failed]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return $this->environment->database->pdo()->query('SELECT * FROM monitoring_daily_job_runs ORDER BY id')->fetchAll();
    }

    private function dashboard(): string
    {
        return (string) $this->signedInBrowser()->get('/admin')->getBody();
    }

    private function utc(int $at): string
    {
        return gmdate('Y-m-d H:i', $at) . ' UTC';
    }

    public function testRunNowRunsTheJobAndShowsTheResults(): void
    {
        $grown = $this->pick('grown');
        $fine = $this->pick('fine');
        $this->recordRootTotal($grown, (int) (9.6 * self::GIB));
        $this->recordRootTotal($fine, (int) (14.5 * self::GIB));
        $browser = $this->signedInBrowser();
        $this->queueListing(['fine' => 15, 'grown' => 20]);

        $response = $this->runNow($browser);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertSame('manual', $this->runs()[0]['triggered_by']);

        $requests = count($this->environment->http->requests);
        $body = (string) $browser->get('/admin')->getBody();
        $this->assertStringContainsString('Last run: ' . $this->utc($this->environment->clock->now()) . ' (manual), took 0 seconds.', $body);
        $this->assertStringContainsString('<td title="The boot disk is 15.0 GiB and its filesystems total 14.5 GiB.">Pass</td></tr>', $body);
        $failure = 'The boot disk is 20.0 GiB, but its filesystems total 9.6 GiB. Rebooting usually extends them (cloud-init); otherwise run growpart and resize2fs.';
        $this->assertStringContainsString('<td title="' . $failure . '">Fail</td></tr>', $body);
        $this->assertStringContainsString("<h3>Disk size failures</h3>\n<ul>\n<li>my-project/grown: {$failure}</li>", $body);
        $this->assertStringNotContainsString('my-project/fine:', $body);
        // The dashboard reads only SQLite.
        $this->assertCount($requests, $this->environment->http->requests);
    }

    public function testRunNowNeedsASignedInAdministrator(): void
    {
        // A valid CSRF token, so the session check is what stops it.
        $stranger = $this->environment->browser('192.0.2.11');
        $response = $stranger->post('/admin/daily-job', Browser::csrfFields($stranger->get('/auth/login')));

        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
        $this->assertSame([], $this->runs());
    }

    public function testRunNowNeedsTheCsrfToken(): void
    {
        $response = $this->signedInBrowser()->post('/admin/daily-job', []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->runs());
    }

    public function testRunNowIsNotAGetRoute(): void
    {
        $this->assertSame(405, $this->signedInBrowser()->get('/admin/daily-job')->getStatusCode());
        $this->assertSame([], $this->runs());
    }

    public function testRunNowWhileRunningShowsTheRunInProgress(): void
    {
        $startedAt = $this->environment->clock->now() - 60;
        $this->insertRun('scheduled', $startedAt, null);
        $browser = $this->signedInBrowser();

        $response = $this->runNow($browser);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertCount(1, $this->runs());
        $this->assertStringContainsString('Running since ' . $this->utc($startedAt) . ' (scheduled).', (string) $browser->get('/admin')->getBody());
    }

    public function testRunNowThatFailsShowsTheFailure(): void
    {
        $this->pick('web');
        // No listing queued: the fake HTTP client throws.
        $browser = $this->signedInBrowser();

        $response = $this->runNow($browser);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(1, $this->runs()[0]['failed']);
        $this->assertStringContainsString(
            'The last run, started at ' . $this->utc($this->environment->clock->now()) . " (manual), failed. The details are in the server's error log.",
            (string) $browser->get('/admin')->getBody(),
        );
    }

    public function testNeverRun(): void
    {
        $body = $this->dashboard();

        $this->assertStringContainsString('<p>The daily job has never run.</p>', $body);
        $this->assertStringContainsString('No scheduled run yet. On the server, the maguari-server-daily-job timer runs the job every day at 06:00 UTC.', $body);
        $this->assertStringContainsString('<form method="post" action="/admin/daily-job">', $body);
        $this->assertStringContainsString('<button type="submit">Run now</button>', $body);
    }

    public function testAKilledRun(): void
    {
        $startedAt = $this->environment->clock->now() - 901;
        $this->insertRun('scheduled', $startedAt, null);

        $this->assertStringContainsString('The last run, started at ' . $this->utc($startedAt) . ' (scheduled), did not finish.', $this->dashboard());
    }

    public function testResultsComeFromTheLastSuccessfulRun(): void
    {
        $web = $this->pick('web');
        $this->recordRootTotal($web, (int) (9.6 * self::GIB));
        $this->queueListing(['web' => 10]);
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Scheduled);
        $succeededAt = $this->environment->clock->now();
        $this->environment->clock->advance(3600);
        $this->insertRun('manual', $this->environment->clock->now(), $this->environment->clock->now(), failed: 1);

        $body = $this->dashboard();

        $this->assertStringContainsString('(manual), failed.', $body);
        $this->assertStringContainsString('The results below are from the last successful run, at ' . $this->utc($succeededAt) . '.', $body);
        $this->assertStringContainsString('">Pass</td></tr>', $body);
    }

    public function testInstancesPickedAfterTheLastRunHaveNoResult(): void
    {
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Scheduled);
        $this->pick('web');

        $this->assertStringContainsString("<td></td>\n<td></td></tr>", $this->dashboard());
    }

    public function testOverdueWhenNoScheduledRunInTwentyFiveHours(): void
    {
        $now = $this->environment->clock->now();
        $this->insertRun('scheduled', $now - 25 * 3600 - 1, $now - 25 * 3600);
        // A manual run does not count: the warning is about the timer.
        $this->insertRun('manual', $now - 60, $now - 59);

        $body = $this->dashboard();

        $this->assertStringContainsString('<strong>The daily job is overdue:</strong> no scheduled run started in the last 25 hours.', $body);
        $this->assertStringNotContainsString('No scheduled run yet.', $body);
    }

    public function testNotOverdueWithinTwentyFiveHours(): void
    {
        $now = $this->environment->clock->now();
        $this->insertRun('scheduled', $now - 25 * 3600, $now - 25 * 3600 + 2);

        $body = $this->dashboard();

        $this->assertStringContainsString('(scheduled), took 2 seconds.', $body);
        $this->assertStringNotContainsString('overdue', $body);
        $this->assertStringNotContainsString('No scheduled run yet.', $body);
    }

    public function testOneSecond(): void
    {
        $now = $this->environment->clock->now();
        $this->insertRun('scheduled', $now - 10, $now - 9);

        $this->assertStringContainsString('(scheduled), took 1 second.', $this->dashboard());
    }
}
