<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The instance page and its hostnames for the remote certificate check
 * (design sections 6.2 and 8.1 item 4).
 */
final class InstancePageFlowTest extends TestCase
{
    private const DAY = 86_400;

    private TestEnvironment $environment;
    private int $instanceId;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => 'web', 'zone' => $zoneUrl, 'status' => 'RUNNING']);
        $this->instanceId = $this->environment->fleet->pickInstance(1, 'us-east1-b', 'web')->id;
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

    private function page(): string
    {
        return '/admin/instances/' . $this->instanceId;
    }

    private function add(Browser $browser, string $hostname): \Psr\Http\Message\ResponseInterface
    {
        return $browser->post($this->page() . '/certificate-hostnames', Browser::csrfFields($browser->get($this->page())) + ['hostname' => $hostname]);
    }

    /**
     * @return list<string>
     */
    private function hostnames(): array
    {
        return array_map(static fn ($known): string => $known->hostname, $this->environment->monitoring->certificateHostnames($this->instanceId));
    }

    public function testShowsTheInstance(): void
    {
        $response = $this->signedInBrowser()->get($this->page());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>web</h1>', $body);
        $this->assertStringContainsString('<a href="/admin/projects/1">my-project</a> · Zone us-east1-b', $body);
        $this->assertStringContainsString('No results yet: the daily job has not finished a run.', $body);
        $this->assertStringContainsString('<p>The daily job has never run.</p>', $body);
        $this->assertStringContainsString('<form method="post" action="/admin/daily-job">', $body);
        $this->assertStringContainsString('<input type="hidden" name="instance_id" value="' . $this->instanceId . '">', $body);
        $this->assertStringContainsString("<h2>Hostnames checked remotely</h2>", $body);
        $this->assertStringContainsString('<p>None yet.</p>', $body);
        $this->assertStringContainsString('<input id="hostname" name="hostname" type="text" value=""', $body);
    }

    public function testAnUnknownInstanceIsNotFound(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertSame(404, $browser->get('/admin/instances/999')->getStatusCode());
        $this->assertSame(404, $browser->post('/admin/instances/999/certificate-hostnames', Browser::csrfFields($browser->get($this->page())) + ['hostname' => 'www.example.com'])->getStatusCode());
        $this->assertSame([], $this->hostnames());
    }

    public function testNeedsASignedInAdministrator(): void
    {
        $stranger = $this->environment->browser('192.0.2.11');

        $this->assertSame('/auth/login', $stranger->get($this->page())->getHeaderLine('Location'));
        $response = $stranger->post($this->page() . '/certificate-hostnames', Browser::csrfFields($stranger->get('/auth/login')) + ['hostname' => 'www.example.com']);
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
        $this->assertSame([], $this->hostnames());
    }

    public function testFormsNeedTheCsrfToken(): void
    {
        $browser = $this->signedInBrowser();
        $added = $this->environment->monitoring->addCertificateHostname($this->instanceId, 'www.example.com');

        $this->assertSame(400, $browser->post($this->page() . '/certificate-hostnames', ['hostname' => 'api.example.com'])->getStatusCode());
        $this->assertSame(400, $browser->post($this->page() . '/certificate-hostnames/' . $added->id . '/remove', [])->getStatusCode());
        $this->assertSame(['www.example.com'], $this->hostnames());
    }

    public function testNoStateChangingGet(): void
    {
        $browser = $this->signedInBrowser();
        $added = $this->environment->monitoring->addCertificateHostname($this->instanceId, 'www.example.com');

        $this->assertSame(405, $browser->get($this->page() . '/certificate-hostnames')->getStatusCode());
        $this->assertSame(405, $browser->get($this->page() . '/certificate-hostnames/' . $added->id . '/remove')->getStatusCode());
        $this->assertSame(['www.example.com'], $this->hostnames());
    }

    public function testAddAndRemoveAHostname(): void
    {
        $browser = $this->signedInBrowser();

        $response = $this->add($browser, ' WWW.Example.com ');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame($this->page(), $response->getHeaderLine('Location'));
        $this->assertSame(['www.example.com'], $this->hostnames());
        $body = (string) $browser->get($this->page())->getBody();
        $this->assertStringContainsString('<tr><td>www.example.com</td>', $body);
        $id = $this->environment->monitoring->certificateHostnames($this->instanceId)[0]->id;
        $this->assertStringContainsString('action="/admin/instances/' . $this->instanceId . '/certificate-hostnames/' . $id . '/remove"', $body);
        // Adding connects to nothing: the next run does.
        $this->assertSame([], $this->environment->tls->reads);

        $response = $browser->post($this->page() . '/certificate-hostnames/' . $id . '/remove', Browser::csrfFields($browser->get($this->page())));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame([], $this->hostnames());
        // Removing it again (a second click) just shows the page.
        $response = $browser->post($this->page() . '/certificate-hostnames/' . $id . '/remove', Browser::csrfFields($browser->get($this->page())));
        $this->assertSame(303, $response->getStatusCode());
    }

    public function testARefusedHostnameKeepsWhatWasTyped(): void
    {
        $browser = $this->signedInBrowser();

        $response = $this->add($browser, 'https://www.example.com/');

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<p>Enter only the hostname, for example www.example.com, without https://, a port or a path.</p>', $body);
        $this->assertStringContainsString('value="https://www.example.com/"', $body);
        $this->assertSame([], $this->hostnames());
    }

    public function testTheFormGoesAwayAtTheLimit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->environment->monitoring->addCertificateHostname($this->instanceId, "site{$i}.example.com");
        }

        $body = (string) $this->signedInBrowser()->get($this->page())->getBody();

        $this->assertStringNotContainsString('id="hostname"', $body);
        $this->assertStringContainsString('This instance has the most hostnames allowed (10). Remove one to add another.', $body);
    }

    public function testSuggestsTheInstancesOwnCertificates(): void
    {
        $monitoring = $this->environment->monitoring;
        $now = $this->environment->clock->now();
        $monitoring->recordReadings($this->instanceId, $now, $monitoring->parseReadings([
            ['metric' => 'certificate_expires_at:example.com', 'value' => $now + 60 * self::DAY],
        ]));
        $browser = $this->signedInBrowser();

        $body = (string) $browser->get($this->page())->getBody();
        $this->assertStringContainsString('<input type="hidden" name="hostname" value="example.com">' . "\n" . 'example.com <button type="submit">Add</button>', $body);

        $this->add($browser, 'example.com');
        $this->assertStringNotContainsString('name="hostname" value="example.com">', (string) $browser->get($this->page())->getBody());
    }

    public function testShowsEachCertificateResultFromTheLastRun(): void
    {
        $monitoring = $this->environment->monitoring;
        $now = $this->environment->clock->now();
        $monitoring->recordReadings($this->instanceId, $now, $monitoring->parseReadings([
            ['metric' => 'certificate_expires_at:www.example.com', 'value' => $now + 60 * self::DAY],
        ]));
        $monitoring->addCertificateHostname($this->instanceId, 'www.example.com');
        $this->environment->tls->serve('www.example.com', $now + 60 * self::DAY);
        $this->environment->http->queueJson(200, ['items' => []]);
        $monitoring->runDailyJob(DailyJobTrigger::Manual);

        $body = (string) $this->signedInBrowser()->get($this->page())->getBody();

        $this->assertStringContainsString('From the last successful run, at ' . gmdate('Y-m-d H:i', $now) . ' UTC.', $body);
        $this->assertStringContainsString('<tr><td>www.example.com</td><td>On the instance</td><td>Pass<span class="info">', $body);
        $this->assertStringContainsString('<tr><td>www.example.com</td><td>Served on port 443</td><td>Pass<span class="info">', $body);

        $dashboard = (string) $this->signedInBrowser()->get('/admin')->getBody();
        $this->assertStringContainsString('<a href="' . $this->page() . '">web</a>', $dashboard);
        $this->assertStringContainsString('>Pass (2)<span class="info">', $dashboard);
        $this->assertStringContainsString("www.example.com, on the instance: Valid until " . gmdate('Y-m-d', $now + 60 * self::DAY) . " (60 days).\nwww.example.com, served: Valid until", $dashboard);
    }

    public function testRunNowComesBackToTheInstancePage(): void
    {
        $now = $this->environment->clock->now();
        $this->environment->monitoring->addCertificateHostname($this->instanceId, 'www.example.com');
        $this->environment->tls->serve('www.example.com', $now + 60 * self::DAY);
        $this->environment->http->queueJson(200, ['items' => []]);
        $browser = $this->signedInBrowser();

        $response = $browser->post('/admin/daily-job', Browser::csrfFields($browser->get($this->page())) + ['instance_id' => (string) $this->instanceId]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame($this->page(), $response->getHeaderLine('Location'));
        $body = (string) $browser->get($this->page())->getBody();
        $this->assertStringContainsString('<p>Last run: ' . gmdate('Y-m-d H:i', $now) . ' UTC (manual), took 0 seconds.</p>', $body);
        $this->assertStringContainsString('<tr><td>www.example.com</td><td>Served on port 443</td><td>Pass<span class="info">', $body);
    }

    public function testAFailedRunNowSaysSoOnTheInstancePage(): void
    {
        // No listing queued: the fake HTTP client throws, so the job fails.
        $browser = $this->signedInBrowser();

        $response = $browser->post('/admin/daily-job', Browser::csrfFields($browser->get($this->page())) + ['instance_id' => (string) $this->instanceId]);

        $this->assertSame($this->page(), $response->getHeaderLine('Location'));
        $this->assertStringContainsString("failed. The details are in the server's error log.", (string) $browser->get($this->page())->getBody());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherInstanceIds(): array
    {
        return [
            'not picked' => ['999'],
            'not a number' => ['1/../../evil'],
            'an address' => ['https://evil.example/'],
            'zero' => ['0'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider otherInstanceIds
     */
    public function testRunNowOtherwiseGoesToTheDashboard(string $instanceId): void
    {
        $this->environment->http->queueJson(200, ['items' => []]);
        $browser = $this->signedInBrowser();

        $response = $browser->post('/admin/daily-job', Browser::csrfFields($browser->get($this->page())) + ['instance_id' => $instanceId]);

        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function testTheProjectPageLinksPickedInstances(): void
    {
        $zone = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['items' => ['zones/us-east1-b' => ['instances' => [
            ['id' => '1', 'name' => 'web', 'zone' => $zone, 'status' => 'RUNNING', 'machineType' => $zone . '/machineTypes/e2-micro'],
            ['id' => '2', 'name' => 'other', 'zone' => $zone, 'status' => 'RUNNING', 'machineType' => $zone . '/machineTypes/e2-micro'],
        ]]]]);

        $body = (string) $this->signedInBrowser()->get('/admin/projects/1')->getBody();

        $this->assertStringContainsString('<tr><td><a href="' . $this->page() . '">web</a></td>', $body);
        $this->assertStringContainsString('<tr><td>other</td>', $body);
    }
}
