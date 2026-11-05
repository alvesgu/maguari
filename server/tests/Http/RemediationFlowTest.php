<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Remediation\Domain\Mode;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The instance page's "Remediation", the unit a check restarts, the actions
 * on the Logs screen and what maintenance does to emails (design sections 7.1,
 * 7.2 and 10.4).
 */
final class RemediationFlowTest extends TestCase
{
    private const FAILED = ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'failed'];
    private const PASSED = ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active'];

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
     * @param array<string, mixed> $check
     */
    private function heartbeat(array $check): void
    {
        $monitoring = $this->environment->monitoring;
        $monitoring->recordHeartbeat($this->instanceId, $this->environment->clock->now(), $monitoring->parseReadings([]), $monitoring->parseCheckResults([$check]));
        $this->environment->clock->advance(60);
        $monitoring->recordHeartbeat($this->instanceId, $this->environment->clock->now(), $monitoring->parseReadings([]), $monitoring->parseCheckResults([$check]));
        $this->environment->eventDelivery()->deliver();
    }

    /**
     * @return list<array{kind: string, given_up_at: ?int, last_failure: ?string}>
     */
    private function alerts(): array
    {
        return $this->environment->database->pdo()->query('SELECT kind, given_up_at, last_failure FROM notifications_alerts ORDER BY id')->fetchAll();
    }

    public function testTheModeStartsAlertOnlyAndCanBeChanged(): void
    {
        $browser = $this->signedInBrowser();
        $this->assertStringContainsString('<option value="alert_only" selected>Alert-only</option>', (string) $browser->get($this->page())->getBody());

        $response = $this->post($browser, '/mode', ['mode' => 'automatic']);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame($this->page(), $response->getHeaderLine('Location'));
        $this->assertSame(Mode::Automatic, $this->environment->remediation->instanceRemediation($this->instanceId)->mode);
        $this->assertStringContainsString('<option value="automatic" selected>Automatic</option>', (string) $browser->get($this->page())->getBody());
    }

    public function testAnUnknownModeIsABadRequest(): void
    {
        $this->assertSame(400, $this->post($this->signedInBrowser(), '/mode', ['mode' => 'maintenance'])->getStatusCode());
    }

    public function testMaintenanceNeedsAnEndInTheNearFutureAndCanBeEnded(): void
    {
        $browser = $this->signedInBrowser();
        $now = $this->environment->clock->now();

        foreach (['' => 'Enter when the maintenance ends', 'tomorrow' => 'Enter when the maintenance ends', '2026-02-31T10:00' => 'Enter when the maintenance ends', gmdate('Y-m-d\TH:i', $now - 60) => 'must end in the future', gmdate('Y-m-d\TH:i', $now + 31 * 86_400) => 'at most 30 days'] as $until => $sentence) {
            $response = $this->post($browser, '/maintenance', ['until' => (string) $until]);
            $this->assertSame(422, $response->getStatusCode(), (string) $until);
            $this->assertStringContainsString($sentence, (string) $response->getBody());
        }

        $end = gmdate('Y-m-d\TH:i', $now + 3600);
        $this->assertSame(303, $this->post($browser, '/maintenance', ['until' => $end])->getStatusCode());
        $this->assertStringContainsString('In maintenance until ' . str_replace('T', ' ', $end) . ' UTC, then back to alert-only.', (string) $browser->get($this->page())->getBody());

        $this->assertSame(303, $this->post($browser, '/maintenance/end')->getStatusCode());
        $this->assertFalse($this->environment->remediation->isInMaintenance($this->instanceId));
        $this->assertStringContainsString('Maintenance until (UTC)', (string) $browser->get($this->page())->getBody());
    }

    public function testACheckCanNameTheUnitToRestart(): void
    {
        $check = $this->environment->monitoring->addLocalHttpCheck($this->instanceId, 'http://127.0.0.1/health', '');
        $browser = $this->signedInBrowser();

        $response = $this->post($browser, '/check-units', ['check_id' => (string) $check->id, 'unit' => 'bad unit!']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('A service name has only letters', (string) $response->getBody());
        $this->assertStringContainsString('value="bad unit!"', (string) $response->getBody());

        $this->assertSame(303, $this->post($browser, '/check-units', ['check_id' => (string) $check->id, 'unit' => 'nginx'])->getStatusCode());
        $this->assertStringContainsString('value="nginx.service"', (string) $browser->get($this->page())->getBody());
    }

    public function testActionsShowOnTheInstancePageTheLogsScreenAndTheDashboardSaysDryRun(): void
    {
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');
        $browser = $this->signedInBrowser();
        $this->post($browser, '/mode', ['mode' => 'automatic']);
        $this->environment->clock->advance(180);
        $this->heartbeat(self::FAILED);
        $this->environment->clock->advance(180);
        $this->heartbeat(self::FAILED);
        $this->assertCount(1, $this->environment->remediation->decideActions());

        $sentence = 'I would restart nginx.service now.';
        $this->assertStringContainsString($sentence, (string) $browser->get($this->page())->getBody());
        $this->assertStringContainsString('<td>Restart</td>', (string) $browser->get($this->page())->getBody());
        $this->assertStringContainsString($sentence, (string) $browser->get('/admin/logs')->getBody());
        $this->assertStringContainsString('Remediation is in dry run', (string) $browser->get('/admin')->getBody());

        $this->assertSame(200, $browser->get('/admin/logs?actions_before=1')->getStatusCode());
        $this->assertStringNotContainsString($sentence, (string) $browser->get('/admin/logs?actions_before=1')->getBody());
        $this->assertSame(400, $browser->get('/admin/logs?actions_before=x')->getStatusCode());
    }

    public function testMaintenanceSendsNoEmailsButStillOpensIncidents(): void
    {
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');
        $this->environment->remediation->startMaintenance($this->instanceId, gmdate('Y-m-d\TH:i', $this->environment->clock->now() + 3600));
        $this->heartbeat(self::FAILED);

        $this->assertCount(1, $this->environment->remediation->incidents($this->instanceId)->open);
        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('opened', $alerts[0]['kind']);
        $this->assertNotNull($alerts[0]['given_up_at']);
        $this->assertSame(NotificationsApi::IN_MAINTENANCE, $alerts[0]['last_failure']);
    }

    public function testAnIncidentOpenedInMaintenanceAndResolvedAfterItSendsNothing(): void
    {
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');
        $this->environment->remediation->startMaintenance($this->instanceId, gmdate('Y-m-d\TH:i', $this->environment->clock->now() + 180));
        $this->heartbeat(self::FAILED);
        $this->environment->remediation->endMaintenance($this->instanceId);
        $this->heartbeat(self::PASSED);
        $this->environment->clock->advance(300);
        $this->assertCount(1, $this->environment->remediation->resolveDue());
        $this->environment->eventDelivery()->deliver();

        $alerts = $this->alerts();
        $this->assertSame('resolved', $alerts[1]['kind']);
        $this->assertNotNull($alerts[1]['given_up_at']);
    }

    public function testWhenMaintenanceEndsAnOpenIncidentGetsItsEmailLateFromTheReconciliation(): void
    {
        $notifications = $this->environment->notifications;
        $notifications->saveSmtpSettings(new \Maguari\Server\Notifications\SmtpSettingsInput('smtp.example.com', '587', '', '', 'alerts@example.com'));
        $this->environment->monitoring->addServiceCheck($this->instanceId, 'nginx');
        $this->environment->remediation->startMaintenance($this->instanceId, gmdate('Y-m-d\TH:i', $this->environment->clock->now() + 7200));
        $this->heartbeat(self::FAILED);

        $notifications->reconcileIfDue($this->environment->remediation, $this->environment->monitoring);
        $this->assertCount(1, $this->alerts(), 'Not while the maintenance lasts.');

        $this->environment->remediation->endMaintenance($this->instanceId);
        $this->environment->clock->advance(3601);
        $reconciliation = $notifications->reconcileIfDue($this->environment->remediation, $this->environment->monitoring);

        $this->assertNotNull($reconciliation);
        $this->assertCount(1, $reconciliation->lateAlerts);
        $this->assertCount(2, $this->alerts());
        $this->assertNull($this->alerts()[1]['given_up_at']);
    }
}
