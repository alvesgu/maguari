<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * The aggregate of what crosses incidents on one instance (design section
 * 3.1 item 7): its mode, its recent actions and the rules they answer to, the
 * cooldowns, the limits and the boot grace period. Every action on an instance
 * is decided here, so no code path can skip them.
 */
final class InstanceRemediation
{
    /**
     * @param int $modeChangedAt when the mode last changed
     * @param ?int $maintenanceUntil when the current or latest maintenance ends or ended
     * @param list<Action> $actions the actions of the last 24 hours and those
     *        serving open incidents, oldest first
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly Mode $mode,
        public readonly int $modeChangedAt,
        public readonly ?int $maintenanceUntil = null,
        public readonly array $actions = [],
    ) {
    }

    /**
     * An instance nobody has set: alert-only, since always.
     */
    public static function newlyPicked(int $instanceId): self
    {
        return new self($instanceId, Mode::AlertOnly, 0);
    }

    public function isInMaintenance(int $at): bool
    {
        return $this->maintenanceUntil !== null && $this->maintenanceUntil > $at;
    }

    /**
     * Setting the mode it already has changes nothing, so the wait before
     * acting does not start over.
     */
    public function withMode(Mode $mode, int $at): self
    {
        return $mode === $this->mode
            ? $this
            : new self($this->instanceId, $mode, $at, $this->maintenanceUntil, $this->actions);
    }

    public function withMaintenanceUntil(int $until): self
    {
        return new self($this->instanceId, $this->mode, $this->modeChangedAt, $until, $this->actions);
    }

    /**
     * Ending a maintenance is what starts the wait over, so an old incident
     * does not act at once.
     */
    public function endMaintenance(int $at): self
    {
        return $this->isInMaintenance($at) ? $this->withMaintenanceUntil($at) : $this;
    }

    /**
     * When actions may start counting the wait from: turning automatic or a
     * maintenance ending never fires an action at once on an old incident.
     */
    public function actingSince(): int
    {
        return max($this->modeChangedAt, $this->maintenanceUntil ?? 0);
    }

    /**
     * What should happen now, given the instance's open incidents.
     *
     * @param list<IncidentSituation> $situations oldest first
     * @param bool $heartbeatOnTime whether the client can be reached
     */
    public function decide(array $situations, bool $heartbeatOnTime, bool $dryRun, int $at): Decision
    {
        if ($this->mode !== Mode::Automatic || $this->isInMaintenance($at)) {
            return new Decision();
        }

        return (new LadderRun($this, $situations, $heartbeatOnTime, $dryRun, $at))->decision();
    }
}
