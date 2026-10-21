<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\Middleware\DeliverEventsMiddleware;
use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Tests\Kernel\Events\RecordingSubscriber;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * An administrator's state-changing request delivers the pending events
 * before it responds (design section 2.1 rule 5), so incidents its action
 * resolved are gone at once. Emails still wait for the tick.
 */
final class AdminEventDeliveryTest extends TestCase
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

    private function pending(): int
    {
        return (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM kernel_events WHERE delivered_at IS NULL')->fetchColumn();
    }

    public function testRemovingAFailingWebAppResolvesItsIncidentAtOnce(): void
    {
        $monitoring = $this->environment->monitoring;
        $this->environment->httpProbe->answer('https://www.example.com/', 503);
        $check = $monitoring->addHttpCheck($this->instanceId, 'https://www.example.com/', '')->check;
        $monitoring->runDueHttpChecks();
        $this->environment->eventDelivery()->deliver();
        $this->assertCount(1, $this->environment->remediation->openIncidents());
        $this->assertCount(1, $this->environment->notifications->openNotifications(1));

        $browser = $this->signedInBrowser();
        $page = '/admin/instances/' . $this->instanceId;
        $response = $browser->post("{$page}/http-checks/{$check->id}/remove", Browser::csrfFields($browser->get($page)));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(0, $this->pending(), 'Withdrawn and resolved within the request.');
        $this->assertSame([], $this->environment->remediation->openIncidents());
        $this->assertSame([], $this->environment->notifications->openNotifications(1));
        $this->assertSame([], $this->environment->mailer->sent, 'Emails wait for the tick.');
    }

    public function testRemovingAHostnameResolvesItsIncidentAtOnce(): void
    {
        $monitoring = $this->environment->monitoring;
        $hostname = $monitoring->addCertificateHostname($this->instanceId, 'www.example.com');
        $since = $this->environment->clock->now();
        $this->environment->database->pdo()->exec(
            "INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since, detail) VALUES ({$this->instanceId}, 'remote_certificate', 'www.example.com', 'fail', {$since}, 'Expired.')",
        );
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckFailed($this->instanceId, 'remote_certificate', 'www.example.com', $since, 'Expired.'))->toEvent(),
        ));
        $this->environment->eventDelivery()->deliver();

        $browser = $this->signedInBrowser();
        $page = '/admin/instances/' . $this->instanceId;
        $browser->post("{$page}/certificate-hostnames/{$hostname->id}/remove", Browser::csrfFields($browser->get($page)));

        $this->assertSame([], $this->environment->remediation->openIncidents());
        $this->assertStringContainsString('<h2>Open incidents</h2>' . "\n" . '<p>None.', (string) $browser->get('/admin')->getBody());
    }

    public function testGetRequestsLeaveEventsToTheTick(): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckFailed($this->instanceId, 'heartbeat_age', '', $this->environment->clock->now(), 'Late.'))->toEvent(),
        ));
        $browser = $this->signedInBrowser();

        $browser->get('/admin');
        $browser->get('/admin/instances/' . $this->instanceId);

        $this->assertSame(1, $this->pending());
    }

    public function testAFailingSubscriberNeverFailsTheRequest(): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckFailed($this->instanceId, 'heartbeat_age', '', $this->environment->clock->now(), 'Late.'))->toEvent(),
        ));
        $failing = new RecordingSubscriber(['monitoring.check_failed'], static function (): void {
            throw new \RuntimeException('A bug.');
        });
        $middleware = new DeliverEventsMiddleware(new EventDelivery($this->environment->database, $this->environment->clock, [$failing]), logErrors: false);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(303);
            }
        };

        $response = $middleware->process((new ServerRequestFactory())->createServerRequest('POST', '/admin/notifications/1/hide'), $handler);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(1, $this->pending(), 'Left for the tick to try again.');
        $this->assertSame(1, (int) $this->environment->database->pdo()->query('SELECT failed_attempts FROM kernel_events')->fetchColumn());
    }
}
