<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * One alert email of a notification and what became of it.
 */
final class Alert
{
    /**
     * @param ?string $lastFailure Maguari's sentence for the latest failure
     * @param ?int $summaryId set when a summary email replaced it
     */
    public function __construct(
        public readonly int $id,
        public readonly int $notificationId,
        public readonly AlertKind $kind,
        public readonly int $createdAt,
        public readonly ?int $sentAt,
        public readonly ?int $givenUpAt,
        public readonly int $attempts,
        public readonly ?string $lastFailure,
        public readonly ?int $summaryId,
    ) {
    }

    public function isPending(): bool
    {
        return $this->sentAt === null && $this->givenUpAt === null;
    }

    /**
     * What became of it, for the Logs screen, for example "Sent 14:03 UTC".
     */
    public function outcome(): string
    {
        $summary = $this->summaryId === null ? '' : ' in a summary';

        return match (true) {
            $this->sentAt !== null => sprintf('Sent%s %s UTC', $summary, gmdate('Y-m-d H:i', $this->sentAt)),
            $this->givenUpAt !== null => sprintf('Not sent, given up %s UTC', gmdate('Y-m-d H:i', $this->givenUpAt)),
            $this->attempts > 0 => sprintf('Not sent yet, %d %s failed', $this->attempts, $this->attempts === 1 ? 'try' : 'tries'),
            default => 'Waiting for the next tick',
        };
    }
}
