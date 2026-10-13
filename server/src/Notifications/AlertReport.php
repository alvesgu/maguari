<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * What one tick's sending step did (design section 13.3).
 */
final class AlertReport
{
    /**
     * @param list<AlertOutcome> $outcomes in the order they happened
     * @param int $recipients how many addresses each email went to
     * @param bool $stoppedEarly whether the time limit left alerts for the next tick
     */
    public function __construct(
        public readonly array $outcomes,
        public readonly int $recipients,
        public readonly bool $stoppedEarly,
    ) {
    }
}
