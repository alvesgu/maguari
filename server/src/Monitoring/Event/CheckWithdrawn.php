<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Event;

use Maguari\Server\Kernel\Events\Event;

/**
 * A check's subject is gone (design section 6.3): an administrator removed a
 * hostname, or a reporting instance no longer reports a certificate. It will
 * never pass or fail again, and its outcome is forgotten.
 */
final class CheckWithdrawn
{
    public const TYPE = 'monitoring.check_withdrawn';

    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly int $at,
    ) {
    }

    /**
     * @throws \UnexpectedValueException when the event is not one of these
     */
    public static function fromEvent(Event $event): self
    {
        $payload = EventPayload::of($event, self::TYPE);

        return new self(
            EventPayload::int($payload, 'instance_id'),
            EventPayload::string($payload, 'check_name'),
            EventPayload::string($payload, 'subject'),
            $event->occurredAt,
        );
    }

    public function toEvent(): Event
    {
        return new Event(self::TYPE, [
            'instance_id' => $this->instanceId,
            'check_name' => $this->checkName,
            'subject' => $this->subject,
        ], $this->at);
    }
}
