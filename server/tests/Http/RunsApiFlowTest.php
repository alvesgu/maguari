<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /admin/api/instances/{id}/runs: stored runs as JSON for charts (design
 * sections 9.1, 10.1 and 10.2).
 */
final class RunsApiFlowTest extends TestCase
{
    private const HOUR = 3600;

    private TestEnvironment $environment;
    private int $instanceId;
    private int $now;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => 'web', 'zone' => $zoneUrl, 'status' => 'RUNNING']);
        $this->instanceId = $this->environment->fleet->pickInstance(1, 'us-east1-b', 'web')->id;
        $this->now = $this->environment->clock->now();
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

    private function insertRun(int $instanceId, string $metric, int|float $value, int $startAt, int $endAt): void
    {
        $this->environment->database->pdo()->prepare(
            'INSERT INTO monitoring_metric_runs (instance_id, metric, value, start_at, end_at) VALUES (?, ?, ?, ?, ?)',
        )->execute([$instanceId, $metric, $value, $startAt, $endAt]);
    }

    private function address(string $query, ?int $instanceId = null): string
    {
        return '/admin/api/instances/' . ($instanceId ?? $this->instanceId) . '/runs?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response, int $status = 200): array
    {
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testReturnsTheRunsOverlappingTheRange(): void
    {
        $from = $this->now - 10 * self::HOUR;
        $to = $this->now - 2 * self::HOUR;
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 7_000_000_000, $from - 3 * self::HOUR, $from - self::HOUR);
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 8_123_456_512, $from - self::HOUR + 60, $from + self::HOUR);
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 8_134_567_890, $from + self::HOUR + 60, $to + self::HOUR);
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 8_200_000_000, $to + self::HOUR + 60, $this->now);
        // Never mixed in: another metric and another instance.
        $this->insertRun($this->instanceId, 'disk_used_bytes:/boot', 1, $from, $to);
        $this->insertRun($this->instanceId, 'disk_total_bytes:/', 2, $from, $to);
        $this->insertRun($this->instanceId + 1, 'disk_used_bytes:/', 3, $from, $to);

        $body = $this->json($this->signedInBrowser()->get($this->address("metric=disk_used_bytes%3A%2F&from={$from}&to={$to}")));

        $this->assertSame([
            'instance_id' => $this->instanceId,
            'metric' => 'disk_used_bytes:/',
            'from' => $from,
            'to' => $to,
            'max_gap_seconds' => 90,
            'truncated' => false,
            'columns' => ['start_at', 'end_at', 'value'],
            // Not clipped: the first starts before from and the last ends after to.
            'runs' => [
                [$from - self::HOUR + 60, $from + self::HOUR, 8_123_456_512],
                [$from + self::HOUR + 60, $to + self::HOUR, 8_134_567_890],
            ],
        ], $body);
    }

    public function testDefaultsToTheLast24Hours(): void
    {
        $this->insertRun($this->instanceId, 'disk_total_bytes:/', 10_213_466_112, $this->now - 25 * self::HOUR, $this->now - 24 * self::HOUR - 1);
        $this->insertRun($this->instanceId, 'disk_total_bytes:/', 10_213_466_112, $this->now - 23 * self::HOUR, $this->now);

        $body = $this->json($this->signedInBrowser()->get($this->address('metric=disk_total_bytes:/')));

        $this->assertSame($this->now - 86_400, $body['from']);
        $this->assertSame($this->now, $body['to']);
        $this->assertSame([[$this->now - 23 * self::HOUR, $this->now, 10_213_466_112]], $body['runs']);
    }

    public function testIntegersStayIntegersAndFractionsStayFractions(): void
    {
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 8_123_456_512, $this->now - 120, $this->now - 60);
        $this->insertRun($this->instanceId, 'disk_used_bytes:/', 1.5, $this->now - 59, $this->now);

        $response = $this->signedInBrowser()->get($this->address('metric=disk_used_bytes:/'));

        $this->assertStringContainsString(
            '"runs":[[' . ($this->now - 120) . ',' . ($this->now - 60) . ',8123456512],[' . ($this->now - 59) . ',' . $this->now . ',1.5]]',
            (string) $response->getBody(),
        );
    }

    public function testAMountPointWithASpaceAndASlash(): void
    {
        $this->insertRun($this->instanceId, 'disk_used_bytes:/mnt/my disk', 5, $this->now - 60, $this->now);

        $body = $this->json($this->signedInBrowser()->get($this->address('metric=disk_used_bytes%3A%2Fmnt%2Fmy%20disk')));

        $this->assertSame('disk_used_bytes:/mnt/my disk', $body['metric']);
        $this->assertSame([[$this->now - 60, $this->now, 5]], $body['runs']);
    }

    public function testAPickedInstanceWithoutRunsGetsAnEmptyList(): void
    {
        $body = $this->json($this->signedInBrowser()->get($this->address('metric=certificate_expires_at:example.com')));

        $this->assertSame([], $body['runs']);
        $this->assertFalse($body['truncated']);
    }

    public function testAnInstanceThatIsNotPickedIsNotFoundEvenWithRuns(): void
    {
        $this->insertRun(999, 'disk_used_bytes:/', 5, $this->now - 60, $this->now);

        $body = $this->json($this->signedInBrowser()->get($this->address('metric=disk_used_bytes:/', 999)), 404);

        $this->assertSame(['error' => 'not_found'], $body);
    }

    public function testABadRequestHasASentence(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertSame(
            ['error' => 'bad_request', 'message' => 'metric is required, for example metric=disk_used_bytes:/.'],
            $this->json($browser->get($this->address('')), 400),
        );
        $this->assertSame(
            ['error' => 'bad_request', 'message' => 'Each parameter may be given only once.'],
            $this->json($browser->get($this->address('metric=disk_used_bytes:/&metric=disk_total_bytes:/')), 400),
        );
        $this->assertStringStartsWith(
            'Unknown metric kind.',
            $this->json($browser->get($this->address('metric=disk_free:/')), 400)['message'],
        );
        $this->assertSame(
            'The range is longer than 31 days.',
            $this->json($browser->get($this->address('metric=disk_used_bytes:/&from=0&to=2678401')), 400)['message'],
        );
    }

    public function testNeedsASignedInAdministrator(): void
    {
        $response = $this->environment->browser('192.0.2.11')->get($this->address('metric=disk_used_bytes:/'));

        $this->assertSame(['error' => 'unauthorized'], $this->json($response, 401));
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    /**
     * The route is in the /admin group, whose CSRF middleware checks every
     * request. A GET changes nothing, so it needs no token.
     */
    public function testAGetPassesTheCsrfCheckWithoutAToken(): void
    {
        $browser = $this->signedInBrowser();
        $address = $this->address('metric=disk_used_bytes:/');

        $this->assertStringNotContainsString('csrf', $address);
        $this->assertSame([], $this->json($browser->get($address))['runs']);
        // The same session's forms still need the token.
        $this->assertSame(400, $browser->post('/admin/logout', [])->getStatusCode());
    }

    public function testOnlyGet(): void
    {
        $browser = $this->signedInBrowser();

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $response = $browser->request($method, $this->address('metric=disk_used_bytes:/'), []);

            $this->assertSame(['error' => 'method_not_allowed'], $this->json($response, 405), $method);
            $this->assertSame('GET', $response->getHeaderLine('Allow'));
        }
    }

    public function testUnknownRoutesAreJson(): void
    {
        $browser = $this->signedInBrowser();

        foreach (['/admin/api', '/admin/api/', '/admin/api/nonexistent', '/admin/api/instances/abc/runs'] as $path) {
            $this->assertSame(['error' => 'not_found'], $this->json($browser->get($path), 404), $path);
        }
    }

    public function testAServerErrorIsJsonWithoutDetails(): void
    {
        $this->environment->database->pdo()->exec('DROP TABLE monitoring_metric_runs');

        $response = $this->signedInBrowser()->get($this->address('metric=disk_used_bytes:/'));

        $this->assertSame(['error' => 'server_error'], $this->json($response, 500));
    }

    public function testThePagesStillRedirectAndKeepTheirHtmlErrors(): void
    {
        $stranger = $this->environment->browser('192.0.2.11');

        $this->assertSame('/auth/login', $stranger->get('/admin/instances/' . $this->instanceId)->getHeaderLine('Location'));
        $this->assertStringStartsWith('text/html', $this->signedInBrowser()->get('/admin/apiary')->getHeaderLine('Content-Type'));
    }
}
