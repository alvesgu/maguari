<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Event;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\Resolution;

/**
 * An incident resolved (design section 7.4), with why. The time is the
 * event's occurred_at, the incident's resolved_at.
 */
final class IncidentResolved
{
    public const TYPE = 'remediation.incident_resolved';

    public function __construct(
        public readonly int $incidentId,
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly int $at,
        public readonly Resolution $resolution,
    ) {
    }

    public static function of(Incident $incident): self
    {
        if ($incident->id === null || $incident->resolvedAt === null || $incident->resolution === null) {
            throw new \LogicException('Only a stored, resolved incident has this event.');
        }

        return new self(
            $incident->id,
            $incident->key->instanceId,
            $incident->key->checkName,
            $incident->key->subject,
            $incident->resolvedAt,
            $incident->resolution,
        );
    }

    /**
     * @throws \UnexpectedValueException when the event is not one of these
     */
    public static function fromEvent(Event $event): self
    {
        $event->expectType(self::TYPE);
        $resolution = Resolution::tryFrom($event->string('resolution'))
            ?? throw new \UnexpectedValueException("The event's resolution is unknown.");

        return new self(
            $event->int('incident_id'),
            $event->int('instance_id'),
            $event->string('check_name'),
            $event->string('subject'),
            $event->occurredAt,
            $resolution,
        );
    }

    public function toEvent(): Event
    {
        return new Event(self::TYPE, [
            'incident_id' => $this->incidentId,
            'instance_id' => $this->instanceId,
            'check_name' => $this->checkName,
            'subject' => $this->subject,
            'resolution' => $this->resolution->value,
        ], $this->at);
    }
}
