<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Event;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Monitoring\Domain\CheckResult;

/**
 * A check's outcome on one instance and subject changed (design section 6.3):
 * CheckFailed or CheckPassed. The time is the event's occurred_at, the time
 * of the result.
 */
abstract class CheckOutcomeChanged
{
    public const TYPE = '';

    /**
     * @param int $instanceId Fleet's instance ID
     * @param string $subject empty for checks with one result per instance
     * @param string $detail the result's sentence, Maguari's own
     */
    final public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly int $at,
        public readonly string $detail,
    ) {
    }

    public static function fromResult(CheckResult $result): static
    {
        return new static($result->instanceId, $result->checkName, $result->subject, $result->checkedAt, $result->detail);
    }

    /**
     * @throws \UnexpectedValueException when the event is not one of these
     */
    public static function fromEvent(Event $event): static
    {
        $payload = EventPayload::of($event, static::TYPE);

        return new static(
            EventPayload::int($payload, 'instance_id'),
            EventPayload::string($payload, 'check_name'),
            EventPayload::string($payload, 'subject'),
            $event->occurredAt,
            EventPayload::string($payload, 'detail'),
        );
    }

    public function toEvent(): Event
    {
        return new Event(static::TYPE, [
            'instance_id' => $this->instanceId,
            'check_name' => $this->checkName,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ], $this->at);
    }
}
