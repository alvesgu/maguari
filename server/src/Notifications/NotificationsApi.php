<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretDecryptionFailed;
use Maguari\Server\Notifications\Exception\EmailNotSent;
use Maguari\Server\Notifications\Exception\EmailNotSetUp;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;
use Maguari\Server\Notifications\Exception\NotificationNotFound;
use Maguari\Server\Remediation\RemediationApi;

/**
 * The Notifications context's public interface (design section 2.1): the
 * notifications of incidents (design section 10.4), the SMTP settings and the
 * test email (design section 13). The SMTP password is encrypted at rest
 * (design section 9.3).
 */
final class NotificationsApi
{
    public const PASSWORD_UNREADABLE = 'The stored SMTP password cannot be decrypted with this server\'s secret key. '
        . 'Enter the password again and save.';
    public const NOT_SET_UP = 'Email is not set up yet. Fill in the SMTP settings and save them first.';
    public const TEST_EMAIL_SUBJECT = 'Maguari test email';

    /** Notifications per page of the Logs screen. */
    public const LOG_PAGE_SIZE = 100;

    public const RECONCILE_EVERY_SECONDS = 86_400;

    private readonly SmtpSettingsRepository $smtpSettings;
    private readonly NotificationRepository $notifications;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
        private readonly Mailer $mailer = new SmtpMailer(),
    ) {
        $this->smtpSettings = new SmtpSettingsRepository($database);
        $this->notifications = new NotificationRepository($database);
    }

    /**
     * What Notifications subscribes to, for the tick's event delivery.
     *
     * @return list<Subscriber>
     */
    public function subscribers(): array
    {
        return [new IncidentEventSubscriber($this->notifications)];
    }

    /**
     * The notifications of open incidents that this administrator has not
     * hidden, newest first, for the dashboard.
     *
     * @return list<Notification>
     */
    public function openNotifications(int $administratorId): array
    {
        return $this->notifications->visibleTo($administratorId);
    }

    /**
     * Hides the notification from this administrator only. Hiding it again
     * changes nothing.
     *
     * @throws NotificationNotFound
     */
    public function hide(int $notificationId, int $administratorId): void
    {
        $at = $this->clock->now();

        $this->database->transaction(function () use ($notificationId, $administratorId, $at): void {
            if (!$this->notifications->exists($notificationId)) {
                throw new NotificationNotFound(sprintf('There is no notification %d.', $notificationId));
            }

            $this->notifications->hide($notificationId, $administratorId, $at);
        });
    }

    /**
     * One page of every notification, hidden or not, resolved or not, newest
     * first, for the Logs screen.
     *
     * @param ?int $before only notifications with a lower ID; null for the newest page
     */
    public function logPage(int $administratorId, ?int $before): LogPage
    {
        // One more than a page, only to tell whether an older page exists.
        $notifications = $this->notifications->page($administratorId, $before, self::LOG_PAGE_SIZE + 1);
        $older = count($notifications) > self::LOG_PAGE_SIZE;
        $notifications = array_slice($notifications, 0, self::LOG_PAGE_SIZE);
        $last = $notifications[count($notifications) - 1] ?? null;
        // The newer page shows the LOG_PAGE_SIZE notifications just above
        // this one: it starts before the one after those, or is the first page.
        $newer = $before === null ? [] : $this->notifications->idsFrom($before, self::LOG_PAGE_SIZE + 1);

        return new LogPage(
            $notifications,
            $older && $last !== null ? $last->id : null,
            $newer !== [],
            $newer[self::LOG_PAGE_SIZE] ?? null,
        );
    }

    /**
     * Notifications are a projection of Remediation's incidents, kept by
     * events. When the last comparison is more than a day old (or none ran),
     * compares the unresolved notifications with Remediation's open
     * incidents, so an event lost to a bug, or delivered before Notifications
     * subscribed, cannot leave them disagreeing for good: an open incident
     * without a notification gets one, and a notification whose incident is
     * no longer open is resolved now. One transaction. Run by the tick when
     * no event is pending.
     *
     * @return ?NotificationReconciliation null when not due
     */
    public function reconcileIfDue(RemediationApi $remediation): ?NotificationReconciliation
    {
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($remediation, $at): ?NotificationReconciliation {
            $last = $this->notifications->lastReconciledAt();

            if ($last !== null && $at - $last <= self::RECONCILE_EVERY_SECONDS) {
                return null;
            }

            $open = [];

            foreach (array_reverse($remediation->openIncidents()) as $incident) {
                $open[(int) $incident->id] = $incident;
            }

            $created = [];
            $resolved = [];
            $known = $this->notifications->incidentsWithNotification(array_keys($open));

            foreach ($open as $incidentId => $incident) {
                if (in_array($incidentId, $known, true)) {
                    continue;
                }

                $key = $incident->key;
                $id = $this->notifications->add($incidentId, $key->instanceId, $key->checkName, $key->subject, $incident->openedAt);
                $created[] = $this->notifications->find((int) $id) ?? throw new \LogicException('The notification just added is missing.');
            }

            foreach ($this->notifications->unresolved() as $notification) {
                if (!isset($open[$notification->incidentId])) {
                    $this->notifications->resolve($notification->incidentId, $at);
                    $resolved[] = $this->notifications->find($notification->id) ?? $notification;
                }
            }

            $this->notifications->recordReconciliation($at, count($created), count($resolved));

            return new NotificationReconciliation($at, $created, $resolved);
        });
    }

    /**
     * A check's name for people, for example "Disk size".
     */
    public static function checkLabel(string $checkName): string
    {
        return CheckLabels::of($checkName);
    }

    /**
     * Null while email is not set up.
     */
    public function smtpSettings(): ?SmtpSettingsSummary
    {
        $row = $this->smtpSettings->find();

        if ($row === null) {
            return null;
        }

        $ciphertext = $row['password_ciphertext'];

        return new SmtpSettingsSummary(
            $row['host'],
            $row['port'],
            $row['username'],
            $ciphertext !== null,
            $ciphertext === null || $this->canDecrypt($ciphertext),
            $row['from_address'],
            $row['updated_at'],
        );
    }

    /**
     * Saves the settings from the Email page. An empty password keeps the
     * stored one while the username stays the same; an empty username removes
     * it.
     *
     * @throws InvalidSmtpSettings
     */
    public function saveSmtpSettings(SmtpSettingsInput $input): void
    {
        $this->database->transaction(function () use ($input): void {
            $this->store($input, $this->smtpSettings->find());
        });
    }

    /**
     * Saves the seed file's [smtp] section (design section 11.5.1), only while
     * no settings are stored: once they are, the web app owns them.
     *
     * @return bool false when settings were already stored, and nothing changed
     * @throws InvalidSmtpSettings
     */
    public function importSmtpSettings(SmtpSettingsInput $input): bool
    {
        return $this->database->transaction(function () use ($input): bool {
            if ($this->smtpSettings->find() !== null) {
                return false;
            }

            $this->store($input, null);

            return true;
        });
    }

    /**
     * Sends the test email with the saved settings, inside the request.
     *
     * @param string $recipient for now the signed-in administrator's address
     * @param string|null $baseUrl the server's configured address, named in
     *                             the body and used as its hostname in EHLO
     * @return string the sentence saying it was sent
     * @throws EmailNotSetUp
     * @throws EmailNotSent with the sentence for the administrator and a line for the log
     */
    public function sendTestEmail(string $recipient, string $administratorName, ?string $baseUrl): string
    {
        $settings = $this->settingsForSending();
        $now = $this->clock->now();
        $from = $baseUrl === null ? '' : ' at ' . $baseUrl;
        $email = new Email(
            $recipient,
            self::TEST_EMAIL_SUBJECT,
            sprintf(
                "This is a test email from Maguari%s, sent at %s UTC by %s.\n\nAlerts will be sent the same way.\n",
                $from,
                gmdate('Y-m-d H:i', $now),
                $administratorName,
            ),
        );
        $localHostname = ($baseUrl === null ? null : parse_url($baseUrl, PHP_URL_HOST)) ?: (gethostname() ?: 'localhost');

        try {
            $this->mailer->send($settings, $email, $localHostname);
        } catch (SendFailure $failure) {
            throw new EmailNotSent(
                SendFailureSentence::for($failure, $settings, $recipient),
                'The test email failed at ' . $failure->detail,
                $failure,
            );
        }

        return sprintf(
            'Sent a test email to %s through %s at %s UTC. If it does not arrive within a few minutes, check the spam folder.',
            $recipient,
            $settings->host,
            gmdate('H:i', $now),
        );
    }

    /**
     * @throws EmailNotSetUp
     * @throws EmailNotSent when the stored password cannot be decrypted
     */
    private function settingsForSending(): SmtpSettings
    {
        $row = $this->smtpSettings->find() ?? throw new EmailNotSetUp(self::NOT_SET_UP);
        $password = '';

        if ($row['password_ciphertext'] !== null) {
            try {
                $password = $this->secretBox->decrypt($row['password_ciphertext']);
            } catch (SecretDecryptionFailed $failed) {
                throw new EmailNotSent(self::PASSWORD_UNREADABLE, 'The test email was not sent: the stored SMTP password cannot be decrypted.', $failed);
            }
        }

        return new SmtpSettings(
            $row['host'],
            $row['port'],
            SmtpEncryption::for($row['host'], $row['port']),
            $row['username'],
            $password,
            $row['from_address'],
        );
    }

    /**
     * @param array{username: string, password_ciphertext: ?string}|null $stored
     * @throws InvalidSmtpSettings
     */
    private function store(SmtpSettingsInput $input, ?array $stored): void
    {
        $storedCiphertext = $stored['password_ciphertext'] ?? null;
        $valid = SmtpSettingsRules::check($input, $storedCiphertext === null ? null : $stored['username']);

        if ($valid['username'] === '') {
            $ciphertext = null;
        } elseif ($input->password !== '') {
            $ciphertext = $this->secretBox->encrypt($input->password);
        } else {
            $ciphertext = $storedCiphertext;
        }

        $this->smtpSettings->save(
            $valid['host'],
            $valid['port'],
            $valid['username'],
            $ciphertext,
            $valid['fromAddress'],
            $this->clock->now(),
        );
    }

    private function canDecrypt(string $ciphertext): bool
    {
        try {
            $this->secretBox->decrypt($ciphertext);

            return true;
        } catch (SecretDecryptionFailed) {
            return false;
        }
    }
}
