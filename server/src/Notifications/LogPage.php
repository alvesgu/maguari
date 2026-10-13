<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * One page of the Logs screen: notifications newest first, with where the
 * older and newer pages start.
 */
final class LogPage
{
    /**
     * @param list<Notification> $notifications newest first
     * @param ?int $olderBefore the "before" of the next older page, null when there is none
     * @param bool $hasNewer whether a newer page exists
     * @param ?int $newerBefore the "before" of the newer page; null for the
     *        first page (no "before" at all)
     * @param array<int, list<Alert>> $alerts by notification ID
     */
    public function __construct(
        public readonly array $notifications,
        public readonly ?int $olderBefore,
        public readonly bool $hasNewer,
        public readonly ?int $newerBefore,
        public readonly array $alerts = [],
    ) {
    }
}
