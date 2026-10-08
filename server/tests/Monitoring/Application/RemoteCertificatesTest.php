<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Application;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Monitoring\Application\ServedCertificates;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Domain\ServedCertificate;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Hostnames per instance and the remote certificate check in the daily job
 * (design section 6.2), with the fake TLS reader.
 */
final class RemoteCertificatesTest extends TestCase
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

    private function queueEmptyListing(): void
    {
        $this->environment->http->queueJson(200, ['items' => []]);
    }

    public function testServedCertificatesTryAVerifiedConnectionFirst(): void
    {
        $tls = $this->environment->tls;
        $now = $this->environment->clock->now();
        $tls->serve('good.example', $now + 60 * self::DAY);
        $tls->serve('bad.example', $now - self::DAY, trusted: false);
        $served = new ServedCertificates($tls);

        $this->assertEquals(new ServedCertificate($now + 60 * self::DAY, true), $served->read('good.example'));
        $this->assertEquals(new ServedCertificate($now - self::DAY, false), $served->read('bad.example'));
        $this->assertNull($served->read('down.example'));
        $this->assertSame([
            ['good.example', 443, true, 5.0],
            ['bad.example', 443, true, 5.0],
            ['bad.example', 443, false, 5.0],
            ['down.example', 443, true, 5.0],
            ['down.example', 443, false, 5.0],
        ], $tls->reads);
    }

    public function testTheJobChecksEachHostnameAfterTheLocalCertificates(): void
    {
        $web = $this->pick('web');
        $monitoring = $this->environment->monitoring;
        $now = $this->environment->clock->now();
        $monitoring->recordReadings($web->id, $now, $monitoring->parseReadings([
            ['metric' => 'certificate_expires_at:www.example.com', 'value' => $now + 80 * self::DAY],
        ]));
        $monitoring->addCertificateHostname($web->id, 'www.example.com');
        $monitoring->addCertificateHostname($web->id, 'api.example.com');
        $monitoring->addCertificateHostname($web->id, 'down.example.com');
        $this->environment->tls->serve('www.example.com', $now + 4 * self::DAY);
        $this->environment->tls->serve('api.example.com', $now + 60 * self::DAY);
        $this->queueEmptyListing();

        $results = $monitoring->runDailyJob(DailyJobTrigger::Manual);

        $this->assertSame([
            ['disk_size', '', CheckOutcome::NotChecked],
            ['local_certificate', 'www.example.com', CheckOutcome::Pass],
            ['remote_certificate', 'api.example.com', CheckOutcome::Pass],
            ['remote_certificate', 'down.example.com', CheckOutcome::NotChecked],
            ['remote_certificate', 'www.example.com', CheckOutcome::Fail],
        ], array_map(static fn (CheckResult $result): array => [$result->checkName, $result->subject, $result->outcome], $results));
        $this->assertStringEndsWith('reload the web server.', $results[4]->detail);

        $summary = $monitoring->dailyJobSummary([$web->id]);
        $this->assertSame(
            ['local_certificate', 'remote_certificate', 'remote_certificate', 'remote_certificate'],
            array_map(static fn (CheckResult $result): string => $result->checkName, $summary->certificateResults[$web->id]),
        );
    }

    public function testAnInstanceWithOnlyHostnamesHasCertificateResults(): void
    {
        $web = $this->pick('web');
        $this->environment->monitoring->addCertificateHostname($web->id, 'www.example.com');
        $this->environment->tls->serve('www.example.com', $this->environment->clock->now() + 60 * self::DAY);
        $this->queueEmptyListing();
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        $this->assertCount(1, $this->environment->monitoring->dailyJobSummary([$web->id])->certificateResults[$web->id]);
    }

    public function testAddingAndRemovingHostnames(): void
    {
        $monitoring = $this->environment->monitoring;
        $added = $monitoring->addCertificateHostname(7, ' WWW.Example.com ');
        $monitoring->addCertificateHostname(7, 'api.example.com');
        $monitoring->addCertificateHostname(8, 'www.example.com');

        $this->assertSame('www.example.com', $added->hostname);
        $this->assertSame($this->environment->clock->now(), $added->addedAt);
        $this->assertSame(['api.example.com', 'www.example.com'], array_map(static fn ($known): string => $known->hostname, $monitoring->certificateHostnames(7)));

        // Only for the instance it belongs to.
        $this->assertFalse($monitoring->removeCertificateHostname(8, $added->id));
        $this->assertTrue($monitoring->removeCertificateHostname(7, $added->id));
        $this->assertFalse($monitoring->removeCertificateHostname(7, $added->id));
        $this->assertSame(['api.example.com'], array_map(static fn ($known): string => $known->hostname, $monitoring->certificateHostnames(7)));
    }

    public function testADuplicateIsRefused(): void
    {
        $this->environment->monitoring->addCertificateHostname(7, 'www.example.com');

        $this->expectException(InvalidCertificateHostname::class);
        $this->expectExceptionMessage('www.example.com is already checked for this instance.');
        $this->environment->monitoring->addCertificateHostname(7, 'WWW.example.com');
    }

    public function testAtMostTenPerInstance(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->environment->monitoring->addCertificateHostname(7, "site{$i}.example.com");
        }

        $this->expectException(InvalidCertificateHostname::class);
        $this->expectExceptionMessage('An instance can have at most 10 hostnames checked remotely.');
        $this->environment->monitoring->addCertificateHostname(7, 'site11.example.com');
    }

    public function testSuggestionsAreRecentLocalCertificatesNotYetChecked(): void
    {
        $monitoring = $this->environment->monitoring;
        $now = $this->environment->clock->now();
        $monitoring->recordReadings(7, $now, $monitoring->parseReadings([
            ['metric' => 'certificate_expires_at:gone.example.com', 'value' => $now + 60 * self::DAY],
        ]));
        $this->environment->clock->advance(2 * self::DAY);
        $now = $this->environment->clock->now();
        $monitoring->recordReadings(7, $now, $monitoring->parseReadings([
            ['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY],
            ['metric' => 'certificate_expires_at:www.example.com', 'value' => $now + 60 * self::DAY],
            ['metric' => 'certificate_expires_at:*.example.org', 'value' => $now + 60 * self::DAY],
            ['metric' => 'certificate_expires_at:intranet', 'value' => $now + 60 * self::DAY],
        ]));
        $monitoring->addCertificateHostname(7, 'www.example.com');

        $this->assertSame(['example.com'], $monitoring->certificateHostnameSuggestions(7));
    }
}
