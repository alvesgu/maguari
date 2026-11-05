<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * An open incident with what the ladder needs to know about its check right
 * now, read fresh on every tick.
 */
final class IncidentSituation
{
    /**
     * @param bool $failing whether Monitoring reports the check failing and
     *        the incident is not waiting to resolve
     * @param ?string $unit the unit its restarts name (Ladder::unit())
     * @param ?int $lastResultAt the time of the check's latest pass or fail,
     *        the server's receive time, repeats included
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly bool $failing,
        public readonly LadderPath $path,
        public readonly ?string $unit = null,
        public readonly ?int $lastResultAt = null,
    ) {
    }

    public function id(): int
    {
        return $this->incident->id ?? throw new \LogicException('Only a stored incident can act.');
    }
}
