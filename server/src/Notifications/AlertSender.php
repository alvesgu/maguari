<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Remediation\RemediationApi;

/**
 * Sends pending alert emails (design section 13.3), only from the tick and
 * outside any transaction, recording each outcome in a short transaction
 * after it. At least once: a tick killed between sending and recording sends
 * that email again on the next tick.
 */
final class AlertSender
{
    /** No new email starts after this long, so one tick never runs into the next. */
    public const TIME_LIMIT_SECONDS = 40;

    public const NOT_SET_UP = 'Email is not set up.';
    public const NO_ADMINISTRATOR = 'There is no administrator to send it to.';
    public const TOO_OLD = 'It could not be sent within 60 minutes.';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly AlertRepository $alerts,
        private readonly NotificationRepository $notifications,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * @param SmtpSettings|string $settings the settings, or the sentence
     *        saying why there are none to use: not set up gives every alert
     *        up at once, anything else (an unreadable password) is a failure
     *        that is tried again
     * @param list<string> $recipients every administrator's address
     * @param list<Instance> $picked the picked instances, for names and the summary rule
     */
    public function send(SmtpSettings|string $settings, array $recipients, array $picked, ?string $baseUrl): AlertReport
    {
        $start = $this->clock->now();
        $pending = $this->alerts->pending();
        $outcomes = [];

        if ($pending === []) {
            return new AlertReport([], count($recipients), false);
        }

        $notifications = $this->notifications->findMany(array_map(static fn (Alert $alert): int => $alert->notificationId, $pending));
        $giveUpNow = match (true) {
            $settings === self::NOT_SET_UP => self::NOT_SET_UP,
            $recipients === [] => self::NO_ADMINISTRATOR,
            default => null,
        };
        $remaining = [];

        foreach ($pending as $alert) {
            $tooOld = $alert->createdAt < $start - NotificationsApi::ALERT_FOR_SECONDS;

            if ($giveUpNow !== null || $tooOld) {
                $sentence = $giveUpNow ?? ($alert->lastFailure === null ? self::TOO_OLD : null);
                $this->database->transaction(fn () => $this->alerts->giveUp([$alert->id], $start, $sentence));
                $outcomes[] = new AlertOutcome($alert->kind, [$notifications[$alert->notificationId]], false, AlertOutcome::GIVEN_UP, $sentence ?? $alert->lastFailure);
            } else {
                $remaining[] = $alert;
            }
        }

        $instances = [];

        foreach ($picked as $instance) {
            $instances[$instance->id] = $instance;
        }

        $units = $this->units($remaining, $notifications, count($picked), $start);
        $stoppedEarly = false;

        foreach ($units as [$kind, $alerts, $summary]) {
            if ($this->clock->now() - $start >= self::TIME_LIMIT_SECONDS) {
                $stoppedEarly = true;

                break;
            }

            $ids = array_map(static fn (Alert $alert): int => $alert->id, $alerts);
            $members = array_map(static fn (Alert $alert): Notification => $notifications[$alert->notificationId], $alerts);

            if (is_string($settings)) {
                $this->database->transaction(fn () => $this->alerts->recordFailure($ids, $settings));
                $outcomes[] = new AlertOutcome($kind, $members, $summary, AlertOutcome::FAILED, $settings);

                continue;
            }

            $late = [];

            foreach ($alerts as $alert) {
                $late[$alert->notificationId] = $alert->late;
            }

            [$subject, $body] = $summary
                ? AlertEmails::summary($kind, $members, $instances, $baseUrl, $late)
                : AlertEmails::single($kind, $members[0], $instances[$members[0]->instanceId] ?? null, $baseUrl, $alerts[0]->late);

            try {
                $this->mailer->send($settings, new Email($recipients, $subject, $body), self::localHostname($baseUrl));
            } catch (SendFailure $failure) {
                $sentence = SendFailureSentence::for($failure, $settings, $recipients, SendFailureSentence::UNEXPECTED_ALERT);
                $this->database->transaction(fn () => $this->alerts->recordFailure($ids, $sentence));
                $outcomes[] = new AlertOutcome($kind, $members, $summary, AlertOutcome::FAILED, $sentence, $failure->detail);

                continue;
            }

            $sentAt = $this->clock->now();
            $this->database->transaction(fn () => $this->alerts->markSent($ids, $sentAt));
            $outcomes[] = new AlertOutcome($kind, $members, $summary, AlertOutcome::SENT);
        }

        return new AlertReport($outcomes, count($recipients), $stoppedEarly);
    }

    /**
     * What to send, oldest first: a summary for each kind whose pending
     * alerts not yet summarized cover a mass failure (the circuit breaker's
     * rule, RemediationApi::isMassFailure), every earlier summary still
     * pending, and every other alert on its own.
     *
     * @param list<Alert> $alerts
     * @param array<int, Notification> $notifications
     * @return list<array{AlertKind, list<Alert>, bool}> kind, alerts and whether it is a summary
     */
    private function units(array $alerts, array $notifications, int $pickedCount, int $at): array
    {
        foreach (AlertKind::cases() as $kind) {
            $loose = array_values(array_filter($alerts, static fn (Alert $alert): bool => $alert->kind === $kind && $alert->summaryId === null));
            $instanceIds = array_unique(array_map(static fn (Alert $alert): int => $notifications[$alert->notificationId]->instanceId, $loose));

            if ($loose !== [] && RemediationApi::isMassFailure(count($instanceIds), $pickedCount)) {
                $summaryId = $this->database->transaction(
                    fn (): int => $this->alerts->summarize($kind, array_map(static fn (Alert $alert): int => $alert->id, $loose), $at),
                );
                $alerts = array_map(
                    static fn (Alert $alert): Alert => in_array($alert, $loose, true)
                        ? new Alert($alert->id, $alert->notificationId, $alert->kind, $alert->createdAt, $alert->sentAt, $alert->givenUpAt, $alert->attempts, $alert->lastFailure, $summaryId, $alert->late)
                        : $alert,
                    $alerts,
                );
            }
        }

        $units = [];

        foreach ($alerts as $alert) {
            $key = $alert->summaryId === null ? 'alert-' . $alert->id : 'summary-' . $alert->summaryId;
            $units[$key] ??= [$alert->kind, [], $alert->summaryId !== null];
            $units[$key][1][] = $alert;
        }

        return array_values($units);
    }

    /**
     * EHLO and Message-ID use the base URL's host, or this machine's name
     * (design section 13.2).
     */
    private static function localHostname(?string $baseUrl): string
    {
        return ($baseUrl === null ? null : parse_url($baseUrl, PHP_URL_HOST)) ?: (gethostname() ?: 'localhost');
    }
}
