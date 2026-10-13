<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Event;

use Maguari\Server\Kernel\Events\Event;

/**
 * Reads the payloads of Monitoring's events back, refusing anything else.
 *
 * @internal
 */
final class EventPayload
{
    /**
     * @return array<string, mixed>
     * @throws \UnexpectedValueException
     */
    public static function of(Event $event, string $type): array
    {
        if ($event->type !== $type) {
            throw new \UnexpectedValueException(sprintf('Expected a %s event, got %s.', $type, $event->type));
        }

        return $event->payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \UnexpectedValueException
     */
    public static function int(array $payload, string $key): int
    {
        return is_int($payload[$key] ?? null) ? $payload[$key] : throw new \UnexpectedValueException("The event's {$key} is not an integer.");
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \UnexpectedValueException
     */
    public static function string(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : throw new \UnexpectedValueException("The event's {$key} is not a string.");
    }
}
