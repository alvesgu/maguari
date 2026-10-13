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

    /**
     * Refuses an event of another type, for classes that read one type back.
     *
     * @throws \UnexpectedValueException
     */
    public function expectType(string $type): void
    {
        if ($this->type !== $type) {
            throw new \UnexpectedValueException(sprintf('Expected a %s event, got %s.', $type, $this->type));
        }
    }

    /**
     * @throws \UnexpectedValueException when the payload has no such integer
     */
    public function int(string $key): int
    {
        $value = $this->payload[$key] ?? null;

        return is_int($value) ? $value : throw new \UnexpectedValueException("The event's {$key} is not an integer.");
    }

    /**
     * A boolean that newer events have and older ones may not: $default when
     * missing.
     *
     * @throws \UnexpectedValueException when present but not a boolean
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->payload[$key] ?? $default;

        return is_bool($value) ? $value : throw new \UnexpectedValueException("The event's {$key} is not a boolean.");
    }

    /**
     * @throws \UnexpectedValueException when the payload has no such string
     */
    public function string(string $key): string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : throw new \UnexpectedValueException("The event's {$key} is not a string.");
    }
}
