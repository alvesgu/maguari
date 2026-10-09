<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

/**
 * A domain event (design section 2.1 rule 5): something that happened in one
 * context, for other contexts to react to. Each context builds its own events
 * in its Event/ folder; this class only carries them through the outbox.
 */
final class Event
{
    /** "<context>.<name>", for example "monitoring.check_failed". */
    private const TYPE_PATTERN = '/^[a-z]+\.[a-z]+(?:_[a-z]+)*$/D';

    /**
     * @param array<string, mixed> $payload IDs and values only, as JSON allows
     * @param int $occurredAt Unix seconds
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly int $occurredAt,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid event type "%s".', $type));
        }
    }
}
