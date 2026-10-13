<?php

declare(strict_types=1);

namespace Maguari\Server\Cli;

use Maguari\Server\Kernel\Events\DeliveryReport;
use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Notifications\Notification;
use Maguari\Server\Notifications\NotificationReconciliation;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\Reconciliation;
use Maguari\Server\Remediation\Domain\ResolutionRule;

/**
 * What `maguari-server tick` prints (design section 12.2.1). Nothing when
 * nothing happened, so the journal stays quiet. Pure, so it is tested without
 * running the tick.
 */
final class TickReport
{
    /**
     * @return list<string> for stdout, without line endings
     */
    public static function lines(DeliveryReport $report): array
    {
        $lines = [];

        foreach ($report->delivered as $event) {
            $lines[] = "Delivered event {$event->id} ({$event->type}).";
        }

        if ($report->limitReached) {
            $lines[] = sprintf(
                'Stopped after %d events; the rest are delivered on the next tick.',
                count($report->delivered) + count($report->failures),
            );
        }

        return $lines;
    }

    /**
     * One line per incident the tick resolved, and per incident the daily
     * reconciliation opened or resolved. Incidents opened from events show as
     * their delivered remediation.incident_opened event.
     *
     * @param list<Incident> $resolved
     * @param array<int, string> $names instance names by ID; an ID is shown
     *        for an instance that is not picked
     * @return list<string> without line endings
     */
    public static function incidentLines(array $resolved, ?Reconciliation $reconciliation, array $names): array
    {
        $lines = [];

        foreach ($resolved as $incident) {
            $lines[] = sprintf('Resolved incident %s: it passed for %d minutes.', self::incident($incident, $names), intdiv(ResolutionRule::RESOLVE_AFTER_SECONDS, 60));
        }

        foreach ($reconciliation->opened ?? [] as $incident) {
            $lines[] = sprintf('Reconciliation opened incident %s: its check is failing but no incident was open.', self::incident($incident, $names));
        }

        foreach ($reconciliation->resolved ?? [] as $incident) {
            $lines[] = sprintf('Reconciliation resolved incident %s: its check is no longer failing.', self::incident($incident, $names));
        }

        return $lines;
    }

    /**
     * One line per notification Notifications' daily reconciliation created
     * or resolved.
     *
     * @param array<int, string> $names instance names by ID
     * @return list<string> without line endings
     */
    public static function notificationLines(?NotificationReconciliation $reconciliation, array $names): array
    {
        $lines = [];

        foreach ($reconciliation->created ?? [] as $notification) {
            $lines[] = sprintf('Reconciliation created notification %s: its incident is open but had none.', self::notification($notification, $names));
        }

        foreach ($reconciliation->resolved ?? [] as $notification) {
            $lines[] = sprintf('Reconciliation resolved notification %s: its incident is no longer open.', self::notification($notification, $names));
        }

        return $lines;
    }

    /**
     * @param array<int, string> $names
     */
    private static function notification(Notification $notification, array $names): string
    {
        return sprintf(
            '%d of incident %d on %s (%s%s)',
            $notification->id,
            $notification->incidentId,
            $names[$notification->instanceId] ?? 'instance ' . $notification->instanceId,
            $notification->checkName,
            $notification->subject === '' ? '' : ' ' . $notification->subject,
        );
    }

    /**
     * @param array<int, string> $names
     */
    private static function incident(Incident $incident, array $names): string
    {
        $key = $incident->key;

        return sprintf(
            '%d on %s (%s%s)',
            (int) $incident->id,
            $names[$key->instanceId] ?? 'instance ' . $key->instanceId,
            $key->checkName,
            $key->subject === '' ? '' : ' ' . $key->subject,
        );
    }

    /**
     * @return list<string> for stderr, one per failed event, without line endings
     */
    public static function errorLines(DeliveryReport $report): array
    {
        $lines = [];

        foreach ($report->failures as $failure) {
            $exception = $failure->exception;
            $line = sprintf(
                'The event %d (%s) failed: %s: %s',
                $failure->id,
                $failure->type,
                $exception::class,
                str_replace(["\r", "\n"], ' ', $exception->getMessage()),
            );
            $line .= $failure->skipped
                ? sprintf(' It failed on %d ticks and is now skipped.', $failure->failedAttempts)
                : sprintf(
                    ' Delivery stops here until the next tick (failed %d of %d times before it is skipped).',
                    $failure->failedAttempts,
                    EventDelivery::MAX_FAILED_ATTEMPTS,
                );
            $lines[] = $line;
        }

        return $lines;
    }
}
