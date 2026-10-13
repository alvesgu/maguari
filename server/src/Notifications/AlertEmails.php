<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Remediation\Domain\Resolution;

/**
 * The text of alert emails (design section 13.3), built from a notification's
 * facts when the email is sent. Plain text; the subject stays ASCII.
 */
final class AlertEmails
{
    /**
     * @param ?Instance $instance null when the instance is no longer picked
     * @param ?string $baseUrl the server's address, for the link; none without it
     * @param bool $late whether to say the incident was detected late
     * @return array{string, string} subject and body
     */
    public static function single(AlertKind $kind, Notification $notification, ?Instance $instance, ?string $baseUrl, bool $late = false): array
    {
        $name = self::instanceName($notification, $instance);
        $what = self::what($notification);
        $lines = $kind === AlertKind::Opened
            ? ["An incident opened on {$name}: {$what}.", '', ...self::paragraph($late ? self::late($notification) : ''), ...self::paragraph($notification->detail)]
            : ["The incident on {$name} is resolved: {$what}.", '', ...self::paragraph(self::resolution($notification))];
        $lines[] = 'Instance: ' . $name;

        if ($instance !== null) {
            $lines[] = 'Project: ' . $instance->gcpProjectId;
            $lines[] = 'Zone: ' . $instance->zone;
        }

        $lines[] = 'Check: ' . CheckLabels::of($notification->checkName);

        if ($notification->subject !== '') {
            $lines[] = 'Subject: ' . $notification->subject;
        }

        $lines[] = 'Opened: ' . self::utc($notification->openedAt);

        if ($kind === AlertKind::Resolved && $notification->resolvedAt !== null) {
            $lines[] = 'Resolved: ' . self::utc($notification->resolvedAt);
        }

        $link = self::link($notification, $baseUrl);

        if ($link !== null) {
            $lines[] = '';
            $lines[] = $link;
        }

        $subject = sprintf('[Maguari] %s on %s: %s', $kind === AlertKind::Opened ? 'Incident' : 'Resolved', $name, $what);

        return [self::ascii($subject), implode("\n", $lines) . "\n"];
    }

    /**
     * One email for many alerts of the same kind (a mass failure or recovery).
     *
     * @param list<Notification> $notifications
     * @param array<int, Instance> $instances picked instances by ID
     * @param array<int, bool> $late by notification ID, whether to say it was detected late
     * @return array{string, string} subject and body
     */
    public static function summary(AlertKind $kind, array $notifications, array $instances, ?string $baseUrl, array $late = []): array
    {
        $instanceCount = count(array_unique(array_map(static fn (Notification $notification): int => $notification->instanceId, $notifications)));
        $verb = $kind === AlertKind::Opened ? 'opened' : 'resolved';
        $incidents = sprintf('%d %s', count($notifications), count($notifications) === 1 ? 'incident' : 'incidents');
        $on = sprintf('%d %s', $instanceCount, $instanceCount === 1 ? 'instance' : 'instances');
        $lines = [
            sprintf('%s %s on %s of the %d picked at once, so they are summarized in this one email.', ucfirst($incidents), $verb, $on, count($instances)),
        ];

        if ($kind === AlertKind::Opened) {
            $lines[] = 'Many instances failing together often points at a problem with the Maguari server\'s own network rather than with the instances.';
        }

        foreach ($notifications as $notification) {
            $instance = $instances[$notification->instanceId] ?? null;
            $where = $instance === null ? self::instanceName($notification, null) : "{$instance->name} ({$instance->gcpProjectId}, {$instance->zone})";
            $when = $kind === AlertKind::Opened ? $notification->openedAt : ($notification->resolvedAt ?? $notification->openedAt);
            $lines[] = '';
            $lines[] = sprintf('- %s: %s, %s %s', $where, self::what($notification), $verb, self::utc($when));

            if ($kind === AlertKind::Opened && ($late[$notification->id] ?? false)) {
                $lines[] = '  ' . self::late($notification);
            }
            $text = $kind === AlertKind::Opened ? $notification->detail : self::resolution($notification);

            if ($text !== '') {
                $lines[] = '  ' . $text;
            }

            $link = self::link($notification, $baseUrl);

            if ($link !== null) {
                $lines[] = '  ' . $link;
            }
        }

        return [self::ascii(sprintf('[Maguari] %s %s on %s', $incidents, $verb, $on)), implode("\n", $lines) . "\n"];
    }

    /**
     * For an incident nobody was told about when it opened: a lost event,
     * or an email given up while it is still open.
     */
    private static function late(Notification $notification): string
    {
        return sprintf('Detected late: failing since %s.', self::utc($notification->openedAt));
    }

    private static function instanceName(Notification $notification, ?Instance $instance): string
    {
        return $instance?->name ?? 'instance ' . $notification->instanceId;
    }

    /**
     * The check and its subject, for example "Served certificate www.example.com".
     */
    private static function what(Notification $notification): string
    {
        return trim(CheckLabels::of($notification->checkName) . ' ' . $notification->subject);
    }

    private static function resolution(Notification $notification): string
    {
        $resolution = $notification->resolution === null ? null : Resolution::tryFrom($notification->resolution);

        return $resolution?->explanation() ?? 'The incident is no longer open.';
    }

    /**
     * @return list<string> the text and a blank line, or nothing when empty
     */
    private static function paragraph(string $text): array
    {
        return $text === '' ? [] : [$text, ''];
    }

    private static function link(Notification $notification, ?string $baseUrl): ?string
    {
        return $baseUrl === null ? null : $baseUrl . '/admin/instances/' . $notification->instanceId;
    }

    private static function utc(int $at): string
    {
        return gmdate('Y-m-d H:i', $at) . ' UTC';
    }

    /**
     * Headers stay ASCII (mbstring is not a dependency): anything else, line
     * breaks included, becomes "?".
     */
    private static function ascii(string $text): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }
}
