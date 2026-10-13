<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * What happened to one alert email on one tick: an individual alert or a
 * summary of several.
 */
final class AlertOutcome
{
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const GIVEN_UP = 'given_up';

    /**
     * @param list<Notification> $notifications one, or a summary's
     * @param string $status SENT, FAILED or GIVEN_UP
     * @param ?string $sentence Maguari's sentence for a failure
     * @param ?string $logDetail one line for the log, never a secret
     */
    public function __construct(
        public readonly AlertKind $kind,
        public readonly array $notifications,
        public readonly bool $summary,
        public readonly string $status,
        public readonly ?string $sentence = null,
        public readonly ?string $logDetail = null,
    ) {
    }
}
