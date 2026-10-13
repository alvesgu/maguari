<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Event;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Remediation\Domain\Incident;

/**
 * An incident opened (design section 7.4). The time is the event's
 * occurred_at, the incident's opened_at.
 */
final class IncidentOpened
{
    public const TYPE = 'remediation.incident_opened';

    /**
     * @param string $detail the failed result's sentence, from Monitoring;
     *        empty when the daily reconciliation opened the incident
     */
    public function __construct(
        public readonly int $incidentId,
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly int $at,
        public readonly string $detail,
    ) {
    }

    /**
     * @param Incident $incident stored, so it has an ID
     */
    public static function of(Incident $incident, string $detail): self
    {
        return new self(
            $incident->id ?? throw new \LogicException('Store the incident before its event.'),
            $incident->key->instanceId,
            $incident->key->checkName,
            $incident->key->subject,
            $incident->openedAt,
            $detail,
        );
    }

    /**
     * @throws \UnexpectedValueException when the event is not one of these
     */
    public static function fromEvent(Event $event): self
    {
        $event->expectType(self::TYPE);

        return new self(
            $event->int('incident_id'),
            $event->int('instance_id'),
            $event->string('check_name'),
            $event->string('subject'),
            $event->occurredAt,
            $event->string('detail'),
        );
    }

    public function toEvent(): Event
    {
        return new Event(self::TYPE, [
            'incident_id' => $this->incidentId,
            'instance_id' => $this->instanceId,
            'check_name' => $this->checkName,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ], $this->at);
    }
}
