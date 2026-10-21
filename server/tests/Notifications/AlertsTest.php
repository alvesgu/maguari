<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Monitoring\Event\CheckWithdrawn;
use Maguari\Server\Notifications\AlertKind;
use Maguari\Server\Notifications\AlertOutcome;
use Maguari\Server\Notifications\AlertReport;
use Maguari\Server\Notifications\Email;
use Maguari\Server\Notifications\Mailer;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SmtpSettings;
use Maguari\Server\Notifications\SmtpSettingsInput;
use Maguari\Server\Notifications\SmtpStage;
use Maguari\Server\Remediation\Domain\Resolution;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use Maguari\Server\Tests\Support\FixedClock;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Alert emails (design section 13.3): one when an incident opens and one
 * when it resolves, a summary when many instances fail at once, retries for
 * 60 minutes and the 40-second limit, all sent only by the tick.
 */
final class AlertsTest extends TestCase
{
    private TestEnvironment $environment;

    /** @var list<Instance> */
    private array $instances = [];

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->access->setBaseUrl('https://maguari.example.com');
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function pick(int $count): void
    {
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';

        for ($i = count($this->instances) + 1; $i <= $count; $i++) {
            $name = sprintf('web-%02d', $i);
            $this->environment->http->queueJson(200, ['id' => (string) $i, 'name' => $name, 'zone' => $zoneUrl, 'status' => 'RUNNING']);
            $this->instances[] = $this->environment->fleet->pickInstance(1, 'us-east1-b', $name);
        }
    }

    private function setUpEmail(): void
    {
        $this->environment->notifications->saveSmtpSettings(new SmtpSettingsInput('smtp.example.com', '587', 'alerts@example.com', 'secret password', 'alerts@example.com'));
    }

    private function publish(IncidentOpened|IncidentResolved ...$events): void
    {
        $outbox = new EventOutbox($this->environment->database);

        foreach ($events as $event) {
            $this->environment->database->transaction(fn () => $outbox->record($event->toEvent()));
        }

        $report = (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->notifications->subscribers()))->deliver();
        $this->assertSame([], $report->failures);
    }

    private function opened(int $incidentId, int $instanceIndex = 0, string $checkName = 'remote_certificate', string $subject = 'www.example.com'): IncidentOpened
    {
        return new IncidentOpened($incidentId, $this->instances[$instanceIndex]->id, $checkName, $subject, $this->environment->clock->now(), 'Expired on 2026-09-30.');
    }

    private function resolved(int $incidentId, int $instanceIndex = 0, string $checkName = 'remote_certificate', string $subject = 'www.example.com'): IncidentResolved
    {
        return new IncidentResolved($incidentId, $this->instances[$instanceIndex]->id, $checkName, $subject, $this->environment->clock->now(), Resolution::Passed);
    }

    private function send(): AlertReport
    {
        return $this->environment->notifications->sendPendingAlerts($this->environment->fleet, $this->environment->access);
    }

    /**
     * @return list<Email>
     */
    private function sent(): array
    {
        return array_map(static fn (array $sent): Email => $sent['email'], $this->environment->mailer->sent);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertRows(): array
    {
        return $this->environment->database->pdo()
            ->query('SELECT notification_id, kind, sent_at, given_up_at, attempts, last_failure, summary_id FROM notifications_alerts ORDER BY id')->fetchAll();
    }

    public function testOneEmailWhenAnIncidentOpensAndOneWhenItResolves(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $openedAt = $this->environment->clock->now();
        $this->publish($this->opened(3));

        $report = $this->send();

        $this->assertSame([AlertOutcome::SENT], array_map(static fn (AlertOutcome $outcome): string => $outcome->status, $report->outcomes));
        [$email] = $this->sent();
        $this->assertSame([TestEnvironment::ADMINISTRATOR_EMAIL], $email->recipients);
        $this->assertSame('[Maguari] Incident on web-01: Served certificate www.example.com', $email->subject);
        $this->assertSame(
            "An incident opened on web-01: Served certificate www.example.com.\n\n"
                . "Expired on 2026-09-30.\n\n"
                . "Instance: web-01\nProject: my-project\nZone: us-east1-b\nCheck: Served certificate\nSubject: www.example.com\n"
                . 'Opened: ' . gmdate('Y-m-d H:i', $openedAt) . " UTC\n\n"
                . 'https://maguari.example.com/admin/instances/' . $this->instances[0]->id . "\n",
            $email->body,
        );
        $this->assertSame('maguari.example.com', $this->environment->mailer->sent[0]['localHostname']);

        $this->assertSame([], $this->send()->outcomes, 'Sent once.');

        $this->environment->clock->advance(600);
        $this->publish($this->resolved(3));
        $this->send();

        $resolved = $this->sent()[1];
        $this->assertSame('[Maguari] Resolved on web-01: Served certificate www.example.com', $resolved->subject);
        $this->assertStringStartsWith("The incident on web-01 is resolved: Served certificate www.example.com.\n\nThe check passed for 5 minutes.\n\n", $resolved->body);
        $this->assertStringContainsString("\nResolved: " . gmdate('Y-m-d H:i', $this->environment->clock->now()) . " UTC\n", $resolved->body);
        $this->assertCount(2, $this->sent());
    }

    public function testEveryAdministratorIsARecipientOfOneEmail(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $this->environment->database->pdo()->exec(
            "INSERT INTO access_administrators (name, email, password_hash, created_at) VALUES ('John Roe', 'john@example.com', 'x', 0)",
        );
        $this->publish($this->opened(3));

        $report = $this->send();

        $this->assertSame(2, $report->recipients);
        $this->assertSame([TestEnvironment::ADMINISTRATOR_EMAIL, 'john@example.com'], $this->sent()[0]->recipients);
    }

    public function testAnInstanceNoLongerPickedAndNoBaseUrl(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $this->environment->database->pdo()->exec("DELETE FROM access_settings WHERE name = 'base_url'");
        $this->publish(new IncidentOpened(3, 99, 'http_status', '', $this->environment->clock->now(), ''));

        $this->send();

        $email = $this->sent()[0];
        $this->assertSame('[Maguari] Incident on instance 99: Other check (http_status)', $email->subject);
        $this->assertSame(
            "An incident opened on instance 99: Other check (http_status).\n\nInstance: instance 99\nCheck: Other check (http_status)\n"
                . 'Opened: ' . gmdate('Y-m-d H:i', $this->environment->clock->now()) . " UTC\n",
            $email->body,
        );
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function massFailures(): array
    {
        return [
            '49% (24 of 49)' => [49, 24, false],
            '50% (2 of 4)' => [4, 2, true],
            '100% of 1 instance' => [1, 1, false],
            '1 of 2' => [2, 1, false],
        ];
    }

    /**
     * @dataProvider massFailures
     */
    public function testASummaryReplacesIndividualAlertsWhenManyInstancesFail(int $picked, int $failing, bool $summary): void
    {
        $this->pick($picked);
        $this->setUpEmail();
        $events = [];

        for ($i = 0; $i < $failing; $i++) {
            $events[] = $this->opened($i + 1, $i, 'heartbeat_age', '');
        }

        $this->publish(...$events);

        $report = $this->send();

        $this->assertCount($summary ? 1 : $failing, $this->sent());
        $this->assertSame($summary, $report->outcomes[0]->summary);

        if ($summary) {
            $email = $this->sent()[0];
            $this->assertSame("[Maguari] {$failing} incidents opened on {$failing} instances", $email->subject);
            $this->assertStringStartsWith(
                "{$failing} incidents opened on {$failing} instances of the {$picked} picked at once, so they are summarized in this one email.\n"
                    . "Many instances failing together often points at a problem with the Maguari server's own network rather than with the instances.\n\n"
                    . '- web-01 (my-project, us-east1-b): Heartbeat age, opened ',
                $email->body,
            );
            $this->assertNotNull($this->alertRows()[0]['summary_id']);
            $this->assertSame([1], array_unique(array_column($this->alertRows(), 'summary_id')));
        }
    }

    public function testRecoveriesAreSummarizedTheSameWay(): void
    {
        $this->pick(2);
        $this->setUpEmail();
        $this->publish($this->opened(1, 0, 'heartbeat_age', ''), $this->opened(2, 1, 'heartbeat_age', ''));
        $this->send();
        $this->environment->clock->advance(600);

        $this->publish($this->resolved(1, 0, 'heartbeat_age', ''), $this->resolved(2, 1, 'heartbeat_age', ''));
        $this->send();

        $this->assertCount(2, $this->sent());
        $this->assertSame('[Maguari] 2 incidents resolved on 2 instances', $this->sent()[1]->subject);
        $this->assertStringContainsString("\n- web-02 (my-project, us-east1-b): Heartbeat age, resolved ", $this->sent()[1]->body);
        $this->assertStringContainsString("\n  The check passed for 5 minutes.\n", $this->sent()[1]->body);
    }

    public function testAFailedEmailIsTriedAgainForSixtyMinutesThenGivenUp(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $this->publish($this->opened(3));
        $this->environment->mailer->failure = new SendFailure(SmtpStage::Connect, null, 'connect: timed out');

        $first = $this->send();

        $this->assertSame(AlertOutcome::FAILED, $first->outcomes[0]->status);
        $this->assertSame('Could not connect to smtp.example.com on port 587, or it did not answer. Check the host and port, and that this server can reach them.', $first->outcomes[0]->sentence);
        $this->assertSame('connect: timed out', $first->outcomes[0]->logDetail);
        $this->environment->clock->advance(3600);
        $this->assertSame(AlertOutcome::FAILED, $this->send()->outcomes[0]->status, 'Still within 60 minutes.');
        $this->environment->clock->advance(1);

        $given = $this->send();

        $this->assertSame(AlertOutcome::GIVEN_UP, $given->outcomes[0]->status);
        $this->assertStringStartsWith('Could not connect to smtp.example.com', (string) $given->outcomes[0]->sentence, 'The last failure.');
        $row = $this->alertRows()[0];
        $this->assertSame(2, $row['attempts']);
        $this->assertSame($this->environment->clock->now(), $row['given_up_at']);
        $this->environment->mailer->failure = null;
        $this->assertSame([], $this->send()->outcomes);
        $this->assertSame([], $this->sent());
    }

    public function testWithoutSmtpSettingsAlertsAreGivenUpAtOnceAndNeverSentLater(): void
    {
        $this->pick(1);
        $this->publish($this->opened(3));

        $report = $this->send();

        $this->assertSame(AlertOutcome::GIVEN_UP, $report->outcomes[0]->status);
        $this->assertSame('Email is not set up.', $report->outcomes[0]->sentence);
        $this->assertSame('Email is not set up.', $this->alertRows()[0]['last_failure']);

        $this->setUpEmail();
        $this->send();
        $this->assertSame([], $this->sent());
    }

    public function testNoNewEmailStartsAfterFortySeconds(): void
    {
        $this->pick(3);
        $this->setUpEmail();
        $this->publish($this->opened(1, 0), $this->opened(2, 0, 'disk_size', ''), $this->opened(3, 0, 'heartbeat_age', ''));
        $clock = $this->environment->clock;
        $slow = new class ($clock) implements Mailer {
            public int $sent = 0;

            public function __construct(private readonly FixedClock $clock)
            {
            }

            public function send(SmtpSettings $settings, Email $email, string $localHostname): void
            {
                $this->sent++;
                $this->clock->advance(25);
            }
        };
        $notifications = new NotificationsApi($this->environment->database, $clock, $this->environment->secretBox, $slow);

        $report = $notifications->sendPendingAlerts($this->environment->fleet, $this->environment->access);

        $this->assertSame(2, $slow->sent, 'The second starts at 25 seconds, the third would start at 50.');
        $this->assertTrue($report->stoppedEarly);
        $this->assertNull($this->alertRows()[2]['sent_at']);

        $notifications->sendPendingAlerts($this->environment->fleet, $this->environment->access);
        $this->assertSame(3, $slow->sent, 'The rest on the next tick.');
    }

    /**
     * Opens an incident in Remediation through Monitoring's event, without
     * delivering Remediation's own event to Notifications.
     */
    private function openIncidentUnseen(int $instanceIndex): void
    {
        $outbox = new EventOutbox($this->environment->database);
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckFailed($this->instances[$instanceIndex]->id, 'heartbeat_age', '', $this->environment->clock->now(), 'Late.'))->toEvent(),
        ));
        (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->remediation->subscribers()))->deliver();
    }

    public function testReconciliationSendsALateOpenedEmailForEveryOpenIncident(): void
    {
        // 2 of 5: not a mass failure, so two individual emails.
        $this->pick(5);
        $this->setUpEmail();
        // Incidents Notifications never heard of: one opened days ago, one just now.
        $oldAt = $this->environment->clock->now();
        $this->openIncidentUnseen(0);
        $this->environment->clock->advance(3 * 86_400);
        $this->openIncidentUnseen(1);

        $reconciliation = $this->environment->notifications->reconcileIfDue($this->environment->remediation, $this->environment->monitoring);
        $this->send();

        $this->assertCount(2, $reconciliation?->created ?? []);
        $this->assertSame([], $reconciliation?->lateAlerts, 'Created ones already have their alert.');
        $this->assertSame(['[Maguari] Incident on web-01: Heartbeat age', '[Maguari] Incident on web-02: Heartbeat age'], array_map(static fn (Email $email): string => $email->subject, $this->sent()));
        $this->assertStringStartsWith(
            "An incident opened on web-01: Heartbeat age.\n\nDetected late: failing since " . gmdate('Y-m-d H:i', $oldAt) . " UTC.\n\n"
                . "See the instance page for details.\n\n",
            $this->sent()[0]->body,
        );
    }

    public function testAnIncidentRemediationReconciledIsDetectedLateToo(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $since = $this->environment->clock->now() - 7200;
        $this->environment->database->pdo()->exec(
            "INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since, detail) VALUES ({$this->instances[0]->id}, 'disk_size', '', 'fail', {$since}, 'Grown.')",
        );
        $this->environment->remediation->reconcileIfDue();
        (new EventDelivery($this->environment->database, $this->environment->clock, $this->environment->notifications->subscribers()))->deliver();

        $this->send();

        $this->assertStringStartsWith(
            "An incident opened on web-01: Disk size.\n\nDetected late: failing since " . gmdate('Y-m-d H:i', $since) . " UTC.\n\nGrown.\n\n",
            $this->sent()[0]->body,
        );
    }

    public function testAnOpenedEmailGivenUpIsSentLateWhileTheIncidentIsStillOpen(): void
    {
        $this->pick(2);
        $remediation = $this->environment->remediation;
        $notifications = $this->environment->notifications;
        $deliverAll = function (): void {
            (new EventDelivery($this->environment->database, $this->environment->clock, [
                ...$this->environment->remediation->subscribers(),
                ...$this->environment->notifications->subscribers(),
            ]))->deliver();
        };
        // Two incidents open, the normal way, while email is not set up:
        // their emails are given up.
        $outbox = new EventOutbox($this->environment->database);

        foreach ([0, 1] as $index) {
            $this->environment->database->transaction(fn () => $outbox->record(
                (new CheckFailed($this->instances[$index]->id, 'heartbeat_age', '', $this->environment->clock->now(), 'Late.'))->toEvent(),
            ));
        }

        $deliverAll();
        $this->send();
        $this->assertSame(['opened', 'opened'], array_column($this->alertRows(), 'kind'));

        $notifications->reconcileIfDue($remediation, $this->environment->monitoring);
        $this->assertCount(2, $this->alertRows(), 'Nothing queued while email is not set up.');

        // Email is set up, and one incident resolves in the meantime.
        $this->setUpEmail();
        $this->environment->database->transaction(fn () => $outbox->record(
            (new CheckWithdrawn($this->instances[1]->id, 'heartbeat_age', '', $this->environment->clock->now()))->toEvent(),
        ));
        $deliverAll();
        $this->environment->clock->advance(3601);

        $reconciliation = $notifications->reconcileIfDue($remediation, $this->environment->monitoring);
        $this->send();

        $this->assertSame([1], array_map(static fn ($notification): int => $notification->incidentId, $reconciliation?->lateAlerts ?? []));
        $this->assertSame(['[Maguari] Incident on web-01: Heartbeat age'], array_map(static fn (Email $email): string => $email->subject, $this->sent()));
        $this->assertStringContainsString("\n\nDetected late: failing since ", $this->sent()[0]->body);
        $page = $notifications->logPage(1, null);
        $labels = array_map(static fn ($alert): string => $alert->label(), $page->alerts[$page->notifications[1]->id]);
        $this->assertSame(['Opened', 'Opened (detected late)'], $labels, 'Both kept, for the Logs screen.');

        // Sent: nothing more on later reconciliations.
        $this->environment->clock->advance(3601);
        $this->assertSame([], $notifications->reconcileIfDue($remediation, $this->environment->monitoring)?->lateAlerts);
    }

    public function testReconciliationSendsResolvedOnlyWhenOpenedWasNotGivenUp(): void
    {
        $this->pick(2);
        // Incident 1's opened alert is given up (no email yet); incident 2's is sent.
        $this->publish($this->opened(1, 0));
        $this->send();
        $this->setUpEmail();
        $this->publish($this->opened(2, 1));
        $this->send();
        // Neither incident is open in Remediation: both notifications are stale.
        $this->environment->notifications->reconcileIfDue($this->environment->remediation, $this->environment->monitoring);

        $this->send();

        $this->assertSame(['[Maguari] Incident on web-02: Served certificate www.example.com', '[Maguari] Resolved on web-02: Served certificate www.example.com'], array_map(static fn (Email $email): string => $email->subject, $this->sent()));
        $this->assertStringContainsString("\n\nThe incident is no longer open.\n\n", $this->sent()[1]->body);
        $this->assertSame(['opened', 'opened', 'resolved'], array_column($this->alertRows(), 'kind'));
    }

    public function testAnUnreadablePasswordIsAFailureThatIsTriedAgain(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $this->environment->database->pdo()->exec("UPDATE notifications_smtp_settings SET password_ciphertext = x'00'");
        $this->publish($this->opened(3));

        $report = $this->send();

        $this->assertSame(AlertOutcome::FAILED, $report->outcomes[0]->status);
        $this->assertSame(NotificationsApi::PASSWORD_UNREADABLE, $report->outcomes[0]->sentence);
        $this->assertSame([], $this->sent());
    }

    public function testTheLogsPageCarriesEachNotificationsAlerts(): void
    {
        $this->pick(1);
        $this->setUpEmail();
        $this->publish($this->opened(3));
        $this->send();

        $page = $this->environment->notifications->logPage(1, null);

        [$alert] = $page->alerts[$page->notifications[0]->id];
        $this->assertSame(AlertKind::Opened, $alert->kind);
        $this->assertSame('Sent ' . gmdate('Y-m-d H:i', $this->environment->clock->now()) . ' UTC', $alert->outcome());
    }
}
