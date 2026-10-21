<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Notifications\CheckLabels;
use Maguari\Server\Notifications\Exception\NotificationNotFound;
use Maguari\Server\Notifications\Notification;
use Maguari\Server\Remediation\Domain\Resolution;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Notifications that follow Remediation's incidents, hidden per
 * administrator, and the Logs screen's pages (design section 10.4).
 */
final class IncidentNotificationsTest extends TestCase
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

    private function publish(IncidentOpened|IncidentResolved $event): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record($event->toEvent()));
    }

    private function opened(int $incidentId, int $instanceId = 7, string $checkName = 'heartbeat_age', string $subject = ''): IncidentOpened
    {
        return new IncidentOpened($incidentId, $instanceId, $checkName, $subject, $this->environment->clock->now(), 'A sentence.');
    }

    private function deliver(): void
    {
        $report = (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->notifications->subscribers()))->deliver();
        $this->assertSame([], $report->failures);
    }

    /**
     * @param list<Notification> $notifications
     * @return list<int>
     */
    private static function incidentIds(array $notifications): array
    {
        return array_map(static fn (Notification $notification): int => $notification->incidentId, $notifications);
    }

    public function testNotificationsFollowIncidents(): void
    {
        $notifications = $this->environment->notifications;
        $openedAt = $this->environment->clock->now();
        $this->publish($this->opened(3, 7, 'remote_certificate', 'www.example.com'));
        $this->environment->clock->advance(60);
        $this->publish($this->opened(4));
        $this->deliver();

        $open = $notifications->openNotifications(1);
        $this->assertSame([4, 3], self::incidentIds($open), 'Newest first.');
        $this->assertEquals(new Notification(1, 3, 7, 'remote_certificate', 'www.example.com', $openedAt, null, false, 'A sentence.'), $open[1]);

        $this->environment->clock->advance(60);
        $this->publish(new IncidentResolved(3, 7, 'remote_certificate', 'www.example.com', $this->environment->clock->now(), Resolution::Withdrawn));
        $this->deliver();

        $this->assertSame([4], self::incidentIds($notifications->openNotifications(1)));
        $logged = $notifications->logPage(1, null)->notifications;
        $this->assertSame([4, 3], self::incidentIds($logged), 'Resolved ones stay on the Logs screen.');
        $this->assertSame($this->environment->clock->now(), $logged[1]->resolvedAt);
    }

    public function testAnIncidentDeliveredTwiceHasOneNotification(): void
    {
        $this->publish($this->opened(3));
        $this->publish($this->opened(3));

        $this->deliver();

        $this->assertCount(1, $this->environment->notifications->logPage(1, null)->notifications);
    }

    public function testResolvingAnIncidentWithoutANotificationDoesNothing(): void
    {
        // Opened before Notifications subscribed.
        $this->publish(new IncidentResolved(3, 7, 'heartbeat_age', '', $this->environment->clock->now(), Resolution::Passed));

        $this->deliver();

        $this->assertSame([], $this->environment->notifications->logPage(1, null)->notifications);
    }

    public function testHidingIsPerAdministrator(): void
    {
        $notifications = $this->environment->notifications;
        $this->publish($this->opened(3));
        $this->deliver();
        $id = $notifications->openNotifications(1)[0]->id;

        $notifications->hide($id, 1);
        $notifications->hide($id, 1);

        $this->assertSame([], $notifications->openNotifications(1));
        $this->assertSame([3], self::incidentIds($notifications->openNotifications(2)));
        $this->assertTrue($notifications->logPage(1, null)->notifications[0]->hidden);
        $this->assertFalse($notifications->logPage(2, null)->notifications[0]->hidden);
    }

    public function testHidingAnUnknownNotificationIsRefused(): void
    {
        $this->expectException(NotificationNotFound::class);

        $this->environment->notifications->hide(99, 1);
    }

    public function testAnUnknownCheckGetsAGenericLabel(): void
    {
        $this->assertSame('Disk size', CheckLabels::of('disk_size'));
        $this->assertSame('Web app', CheckLabels::of('remote_http'));
        $this->assertSame('Other check (http_status)', CheckLabels::of('http_status'));
        $this->assertSame('Other check', CheckLabels::of(''));
        $this->assertSame('Other check (weird)', CheckLabels::of('<Weird>'));
    }

    /**
     * Opens incidents in Remediation through Monitoring's events, without
     * delivering Remediation's own events to Notifications.
     */
    private function openIncidentWithoutNotification(int $instanceId, string $checkName): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckFailed($instanceId, $checkName, '', $this->environment->clock->now(), 'It failed.'))->toEvent(),
        ));
        (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->remediation->subscribers()))->deliver();
    }

    public function testReconciliationCreatesMissingAndResolvesStaleNotifications(): void
    {
        $notifications = $this->environment->notifications;
        $remediation = $this->environment->remediation;
        // Opened before Notifications subscribed: incidents 1 and 2.
        $this->openIncidentWithoutNotification(7, 'heartbeat_age');
        $openedAt = $this->environment->clock->now();
        $this->environment->clock->advance(60);
        $this->openIncidentWithoutNotification(8, 'disk_size');
        // A notification whose incident 9 never existed or was resolved unseen.
        $this->publish($this->opened(9));
        $this->deliver();
        $this->environment->clock->advance(60);

        $reconciliation = $notifications->reconcileIfDue($remediation, $this->environment->monitoring);

        $this->assertNotNull($reconciliation);
        $this->assertSame([1, 2], self::incidentIds($reconciliation->created), 'Oldest first.');
        $this->assertSame($openedAt, $reconciliation->created[0]->openedAt, 'When the incident opened.');
        $this->assertSame([9], self::incidentIds($reconciliation->resolved));
        $this->assertSame($this->environment->clock->now(), $reconciliation->resolved[0]->resolvedAt);
        $this->assertSame([2, 1], self::incidentIds($notifications->openNotifications(1)));
        $this->assertSame(
            [[$this->environment->clock->now(), 2, 1]],
            array_map('array_values', $this->environment->database->pdo()->query('SELECT ran_at, created, resolved FROM notifications_reconciliations')->fetchAll()),
        );
    }

    public function testReconciliationTakesTheSentenceOfTheFailingCheckFromMonitoring(): void
    {
        $this->openIncidentWithoutNotification(7, 'disk_size');
        $this->openIncidentWithoutNotification(8, 'disk_size');
        $this->openIncidentWithoutNotification(9, 'disk_size');
        // Instance 7 is still failing, 8 passes again (its incident waits),
        // and 9 has no outcome in Monitoring.
        $this->environment->database->pdo()->exec(
            "INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since, detail) VALUES "
                . "(7, 'disk_size', '', 'fail', 0, 'Grown.'), (8, 'disk_size', '', 'pass', 0, 'Fine.')",
        );

        $reconciliation = $this->environment->notifications->reconcileIfDue($this->environment->remediation, $this->environment->monitoring);

        $this->assertSame(
            ['Grown.', 'See the instance page for details.', 'See the instance page for details.'],
            array_map(static fn ($notification): string => $notification->detail, $reconciliation?->created ?? []),
        );
    }

    public function testReconciliationRunsAtOnceWhenItNeverRanAndThenOnceAnHour(): void
    {
        $notifications = $this->environment->notifications;
        $remediation = $this->environment->remediation;

        $this->assertNotNull($notifications->reconcileIfDue($remediation, $this->environment->monitoring));
        $this->environment->clock->advance(3_600);
        $this->assertNull($notifications->reconcileIfDue($remediation, $this->environment->monitoring), 'Exactly an hour later is not more than an hour.');
        $this->environment->clock->advance(1);
        $first = $notifications->reconcileIfDue($remediation, $this->environment->monitoring);
        $this->assertNotNull($first);
        $this->assertSame([], $first->created);
        $this->assertSame([], $first->resolved);
    }

    public function testTheLogsScreenPagesBy100(): void
    {
        for ($i = 1; $i <= 250; $i++) {
            $this->publish($this->opened($i));
        }

        $this->deliver();
        $notifications = $this->environment->notifications;

        $first = $notifications->logPage(1, null);
        $this->assertCount(100, $first->notifications);
        $this->assertSame(250, $first->notifications[0]->id);
        $this->assertSame(151, $first->olderBefore);
        $this->assertFalse($first->hasNewer);

        $second = $notifications->logPage(1, 151);
        $this->assertSame([150, 51], [$second->notifications[0]->id, $second->notifications[99]->id]);
        $this->assertSame(51, $second->olderBefore);
        $this->assertTrue($second->hasNewer);
        $this->assertNull($second->newerBefore, 'The newer page is the first one.');

        $last = $notifications->logPage(1, 51);
        $this->assertCount(50, $last->notifications);
        $this->assertNull($last->olderBefore);
        $this->assertTrue($last->hasNewer);
        $this->assertSame(151, $last->newerBefore, 'The 100 just above it.');

        $this->assertSame([], $notifications->logPage(1, 1)->notifications);
    }
}
