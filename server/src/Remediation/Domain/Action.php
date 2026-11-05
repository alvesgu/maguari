<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * Something Maguari decided to do to fix a problem (glossary), an entity
 * inside InstanceRemediation. It can serve several incidents, so two incidents
 * asking for the same restart get one restart.
 */
final class Action
{
    /**
     * @param ?int $id null until stored
     * @param ?string $unit set only for a restart
     * @param list<int> $incidentIds the incidents it serves
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $instanceId,
        public readonly ActionKind $kind,
        public readonly ?string $unit,
        public readonly bool $dryRun,
        public readonly int $decidedAt,
        public readonly ?int $finishedAt,
        public readonly ?ActionOutcome $outcome,
        public readonly array $incidentIds,
    ) {
    }

    /**
     * Decided under dry-run: recorded as finished, with nothing done.
     *
     * @param list<int> $incidentIds
     */
    public static function wouldRun(int $instanceId, ActionKind $kind, ?string $unit, int $at, array $incidentIds): self
    {
        return new self(null, $instanceId, $kind, $unit, true, $at, $at, ActionOutcome::WouldRun, $incidentIds);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->instanceId, $this->kind, $this->unit, $this->dryRun, $this->decidedAt, $this->finishedAt, $this->outcome, $this->incidentIds);
    }

    public function linkedTo(int $incidentId): self
    {
        if ($this->serves($incidentId)) {
            return $this;
        }

        return new self($this->id, $this->instanceId, $this->kind, $this->unit, $this->dryRun, $this->decidedAt, $this->finishedAt, $this->outcome, [...$this->incidentIds, $incidentId]);
    }

    public function serves(int $incidentId): bool
    {
        return in_array($incidentId, $this->incidentIds, true);
    }

    /**
     * Whether the incident's ladder may go on after this restart (design
     * section 7.1): its check has a result newer than the restart's end.
     * Strictly newer, because a result in the heartbeat that brought the
     * outcome may have run before the restart ended.
     */
    public function letsLadderGoOn(?int $lastResultAt): bool
    {
        return $this->finishedAt !== null && $lastResultAt !== null && $lastResultAt > $this->finishedAt;
    }

    /**
     * The sentence for pages and the tick's output.
     */
    public function sentence(): string
    {
        $what = match ($this->kind) {
            ActionKind::Restart => 'restart ' . $this->unit,
            ActionKind::Reboot => 'reboot the instance',
            ActionKind::Reset => 'reset the instance',
        };

        return sprintf('I would %s now.', $what);
    }
}
