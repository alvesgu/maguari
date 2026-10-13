<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Remediation\Domain\Resolution;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's open incidents with "Hide", and the Logs screen (design
 * section 10.4).
 */
final class NotificationsFlowTest extends TestCase
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

    private function publish(IncidentOpened|IncidentResolved ...$events): void
    {
        $outbox = new EventOutbox($this->environment->database);

        foreach ($events as $event) {
            $this->environment->database->transaction(fn () => $outbox->record($event->toEvent()));
        }

        (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->notifications->subscribers()))->deliver();
    }

    private function open(int $incidentId, string $checkName = 'remote_certificate', string $subject = 'www.example.com', ?int $instanceId = null): IncidentOpened
    {
        return new IncidentOpened($incidentId, $instanceId ?? $this->instanceId, $checkName, $subject, $this->environment->clock->now(), 'Expired.');
    }

    private static function utc(int $at): string
    {
        return gmdate('Y-m-d H:i', $at) . ' UTC';
    }

    public function testTheDashboardListsOpenIncidentsWithHide(): void
    {
        $this->publish($this->open(1), $this->open(2, 'heartbeat_age', '', 99));
        $openedAt = $this->environment->clock->now();

        $body = (string) $this->signedInBrowser()->get('/admin')->getBody();

        $this->assertStringContainsString('<a href="/admin/logs">Logs</a>', $body);
        $this->assertStringContainsString(
            '<tr><td>' . self::utc($openedAt) . '</td><td><a href="/admin/instances/' . $this->instanceId . '">my-project/web</a></td><td>Served certificate</td><td>www.example.com</td>'
                . '<td><form method="post" action="/admin/notifications/1/hide">',
            $body,
        );
        $this->assertStringContainsString('<td>Instance 99</td><td>Heartbeat age</td><td></td>', $body, 'An instance that is not picked has no link.');
        $this->assertLessThan(strpos($body, '<h2>Instances</h2>'), strpos($body, '<h2>Open incidents</h2>'));
    }

    public function testNoOpenIncidents(): void
    {
        $body = (string) $this->signedInBrowser()->get('/admin')->getBody();

        $this->assertStringContainsString('<h2>Open incidents</h2>' . "\n" . '<p>None.', $body);
    }

    public function testHideRemovesItFromTheDashboardButNotFromLogs(): void
    {
        $this->publish($this->open(1));
        $browser = $this->signedInBrowser();
        $dashboard = $browser->get('/admin');

        $response = $browser->post('/admin/notifications/1/hide', Browser::csrfFields($dashboard));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertStringNotContainsString('/admin/notifications/1/hide', (string) $browser->get('/admin')->getBody());
        $logs = (string) $browser->get('/admin/logs')->getBody();
        $this->assertStringContainsString('<td>www.example.com</td><td>Open</td><td>Hidden by you</td>', $logs);
    }

    public function testAResolvedIncidentLeavesTheDashboardButNotLogs(): void
    {
        $this->publish($this->open(1));
        $this->environment->clock->advance(300);
        $this->publish(new IncidentResolved(1, $this->instanceId, 'remote_certificate', 'www.example.com', $this->environment->clock->now(), Resolution::Passed));
        $browser = $this->signedInBrowser();

        $this->assertStringNotContainsString('/admin/notifications/1/hide', (string) $browser->get('/admin')->getBody());
        $this->assertStringContainsString('<td>www.example.com</td><td>' . self::utc($this->environment->clock->now()) . '</td><td></td>', (string) $browser->get('/admin/logs')->getBody());
    }

    public function testHideNeedsPostAndTheCsrfToken(): void
    {
        $this->publish($this->open(1));
        $browser = $this->signedInBrowser();

        $this->assertSame(400, $browser->post('/admin/notifications/1/hide', [])->getStatusCode());
        $this->assertSame(405, $browser->get('/admin/notifications/1/hide')->getStatusCode());
        $this->assertStringContainsString('/admin/notifications/1/hide', (string) $browser->get('/admin')->getBody());
    }

    public function testHidingAnUnknownNotificationIsNotFound(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertSame(404, $browser->post('/admin/notifications/99/hide', Browser::csrfFields($browser->get('/admin')))->getStatusCode());
    }

    public function testLogsAndHideNeedASignedInAdministrator(): void
    {
        $this->publish($this->open(1));
        $browser = $this->environment->browser();

        $logs = $browser->get('/admin/logs');
        $hide = $browser->post('/admin/notifications/1/hide', []);

        $this->assertSame(303, $logs->getStatusCode());
        $this->assertSame('/auth/login', $logs->getHeaderLine('Location'));
        $this->assertNotSame('/admin', $hide->getHeaderLine('Location'), 'Not hidden.');
        $this->assertCount(1, $this->environment->notifications->openNotifications(1));
    }

    public function testLogsPagesWithOlderAndNewerLinks(): void
    {
        $events = [];

        for ($i = 1; $i <= 101; $i++) {
            $events[] = $this->open($i);
        }

        $this->publish(...$events);
        $browser = $this->signedInBrowser();

        $first = (string) $browser->get('/admin/logs')->getBody();
        $this->assertStringContainsString('<p><a href="/admin/logs?before=2">Older</a></p>', $first);
        $this->assertStringNotContainsString('>Newer<', $first);

        $older = (string) $browser->get('/admin/logs?before=2')->getBody();
        $this->assertSame(1, substr_count($older, '<td>www.example.com</td>'));
        $this->assertStringContainsString('<p><a href="/admin/logs">Newer</a></p>', $older);
    }

    public function testLogsRefusesAnInvalidBefore(): void
    {
        $browser = $this->signedInBrowser();

        foreach (['?before=abc', '?before=0', '?before=1&before=2', '?before='] as $query) {
            $this->assertSame(400, $browser->get('/admin/logs' . $query)->getStatusCode(), $query);
        }

        $this->assertStringContainsString('No notifications yet.', (string) $browser->get('/admin/logs')->getBody());
    }
}
