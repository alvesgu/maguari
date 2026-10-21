<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Clients\EnrolledClient;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The instance page's "Services" (design section 6.1.3), what it says about
 * the client's settings, and the dashboard's Checks column.
 */
final class ServiceChecksFlowTest extends TestCase
{
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

    /**
     * @param array<string, string> $fields
     */
    private function post(Browser $browser, string $path, array $fields = []): ResponseInterface
    {
        return $browser->post($this->page() . $path, Browser::csrfFields($browser->get($this->page())) + $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function heartbeat(EnrolledClient $client, array $fields = []): void
    {
        $this->environment->clients->recordHeartbeat($client->clientId, json_encode($fields + [
            'protocol_version' => 1,
            'client_version' => '0.4.0',
            'client_id' => $client->clientId,
            'sent_at' => $this->environment->clock->now(),
        ], JSON_THROW_ON_ERROR));
    }

    public function testAddAndRemoveAService(): void
    {
        $browser = $this->signedInBrowser();
        $this->assertStringContainsString('<p>The client has not sent a heartbeat yet. It gets these checks with its first one.</p>', (string) $browser->get($this->page())->getBody());

        $response = $this->post($browser, '/services', ['unit' => 'nginx']);

        $this->assertSame(303, $response->getStatusCode());
        $checks = $this->environment->monitoring->clientChecks($this->instanceId);
        $this->assertSame(['nginx.service'], array_map(static fn ($check): string => $check->target, $checks));
        $this->assertStringContainsString('<tr><td>nginx.service</td><td>Not checked', (string) $browser->get($this->page())->getBody());

        $this->assertSame(303, $this->post($browser, "/client-checks/{$checks[0]->id}/remove")->getStatusCode());
        $this->assertSame([], $this->environment->monitoring->clientChecks($this->instanceId));
        $this->assertSame(303, $this->post($browser, "/client-checks/{$checks[0]->id}/remove")->getStatusCode(), 'A second click just shows the page.');
    }

    public function testARefusedServiceKeepsWhatWasTyped(): void
    {
        $response = $this->post($this->signedInBrowser(), '/services', ['unit' => 'nginx; reboot']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('only letters, digits and : _ . \ - characters', $body);
        $this->assertStringContainsString('value="nginx; reboot"', $body);
        $this->assertSame([], $this->environment->monitoring->clientChecks($this->instanceId));
    }

    public function testSaysWhetherTheClientHasTheSettings(): void
    {
        $client = $this->environment->enrollClient($this->instanceId);
        $browser = $this->signedInBrowser();
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');

        $this->heartbeat($client, ['client_version' => '0.3.0']);
        $this->assertStringContainsString('<p>This client (version 0.3.0) does not run checks. It needs version 0.4.0 or later.</p>', (string) $browser->get($this->page())->getBody());

        $this->heartbeat($client, ['check_settings_version' => null]);
        $this->assertStringContainsString('<p>Waiting for the client to pick up the latest change with its next heartbeat.</p>', (string) $browser->get($this->page())->getBody());

        $version = $this->environment->monitoring->clientCheckSettings($this->instanceId)->version;
        $this->heartbeat($client, ['check_settings_version' => $version, 'checks' => [['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active']]]);
        $body = (string) $browser->get($this->page())->getBody();
        $this->assertStringNotContainsString('Waiting for the client', $body);
        $this->assertMatchesRegularExpression('#<tr><td>nginx.service</td><td>Pass<span class="info">.*?>nginx.service is active.</span></span></td><td>0 s ago</td>#', $body);

        $dashboard = (string) $browser->get('/admin')->getBody();
        $this->assertMatchesRegularExpression('#<td>Pass \(1\)<span class="info">.*?>Service nginx.service: Pass. nginx.service is active.</span></span></td></tr>#', $dashboard);
    }

    public function testFormsNeedTheCsrfToken(): void
    {
        $browser = $this->signedInBrowser();
        $check = $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');

        $this->assertSame(400, $browser->post($this->page() . '/services', ['unit' => 'php'])->getStatusCode());
        $this->assertSame(400, $browser->post($this->page() . "/client-checks/{$check->id}/remove", [])->getStatusCode());
        $this->assertSame(405, $browser->get($this->page() . "/client-checks/{$check->id}/remove")->getStatusCode());
        $this->assertCount(1, $this->environment->monitoring->clientChecks($this->instanceId));
    }
}
