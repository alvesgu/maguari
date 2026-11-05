<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * One evaluation of the ladder on one instance, oldest incident first, for
 * InstanceRemediation::decide() (design section 7.1). Only that class uses it.
 */
final class LadderRun
{
    private const HOUR = 3_600;
    private const DAY = 86_400;

    /** @var list<Action> this run's mode of actions (dry-run or real), then the new ones */
    private array $actions;

    /** @var list<Action> */
    private array $newActions = [];

    /** @var array<int, Action> by ID */
    private array $changedActions = [];

    /** @var array<int, Incident> by ID */
    private array $incidents = [];

    private bool $restartDecided = false;

    /**
     * @param list<IncidentSituation> $situations oldest first
     */
    public function __construct(
        private readonly InstanceRemediation $instance,
        private readonly array $situations,
        private readonly bool $heartbeatOnTime,
        private readonly bool $dryRun,
        private readonly int $at,
    ) {
        // Dry-run actions count only against dry-run actions, so turning
        // dry-run off starts clean.
        $this->actions = array_values(array_filter($instance->actions, static fn (Action $action): bool => $action->dryRun === $dryRun));
    }

    public function decision(): Decision
    {
        if (!$this->inBootGrace()) {
            foreach ($this->situations as $situation) {
                if ($this->canAct($situation) && $this->evaluate($situation)) {
                    break;
                }
            }
        }

        return new Decision($this->newActions, array_values($this->changedActions), array_values($this->incidents));
    }

    private function inBootGrace(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->kind->isInstanceWide() && $this->at - $action->decidedAt < RemediationLimits::BOOT_GRACE_SECONDS) {
                return true;
            }
        }

        return false;
    }

    private function canAct(IncidentSituation $situation): bool
    {
        return $situation->failing
            && $situation->path !== LadderPath::AlertOnly
            && $situation->incident->exhaustedAt === null
            && $this->at - max($situation->incident->openedAt, $this->instance->actingSince()) >= RemediationLimits::ACT_AFTER_SECONDS;
    }

    /**
     * @return bool whether an instance-wide action was decided, which ends the
     *         run: at most one per tick, and it already serves every incident
     *         it can
     */
    private function evaluate(IncidentSituation $situation): bool
    {
        $step = $situation->incident->ladderStep;

        if ($situation->path === LadderPath::ResetOnly || $step >= Ladder::STEP_REBOOT) {
            return $this->wantReset($situation);
        }

        if ($step === Ladder::STEP_NONE) {
            $this->wantRestart($situation, Ladder::STEP_RESTART);

            return false;
        }

        if (!$this->lastRestartLetsLadderGoOn($situation)) {
            return false;
        }

        if ($step === Ladder::STEP_RESTART) {
            $this->wantRestart($situation, Ladder::STEP_RESTART_AGAIN);

            return false;
        }

        return $this->wantReboot($situation);
    }

    private function lastRestartLetsLadderGoOn(IncidentSituation $situation): bool
    {
        $last = null;

        foreach ($this->actions as $action) {
            if ($action->kind === ActionKind::Restart && $action->serves($situation->id())) {
                $last = $action;
            }
        }

        return $last === null || $last->letsLadderGoOn($situation->lastResultAt);
    }

    private function wantRestart(IncidentSituation $situation, int $step): void
    {
        if (!$this->heartbeatOnTime) {
            return;
        }

        // Already requested: take the step with the restart that just
        // happened, instead of asking for another.
        $latest = $this->latestRestartOf((string) $situation->unit);

        if ($latest !== null && !$latest->serves($situation->id()) && $this->at - $latest->decidedAt < RemediationLimits::RESTART_COOLDOWN_SECONDS) {
            $this->link($latest, $situation);
            $this->reach($situation, $step);

            return;
        }

        if ($this->countSince(ActionKind::Restart, self::HOUR) >= RemediationLimits::MAX_RESTARTS_PER_HOUR) {
            $this->hold($situation, LadderHold::RestartLimit);

            return;
        }

        $this->addAction(ActionKind::Restart, $situation->unit, [$situation->id()]);
        $this->restartDecided = true;
        $this->reach($situation, $step);
    }

    private function latestRestartOf(string $unit): ?Action
    {
        $latest = null;

        foreach ($this->actions as $action) {
            if ($action->kind === ActionKind::Restart && $action->unit === $unit) {
                $latest = $action;
            }
        }

        return $latest;
    }

    private function wantReboot(IncidentSituation $situation): bool
    {
        // A reboot needs the client, and waits for a restart decided just now.
        if (!$this->heartbeatOnTime || $this->restartDecided) {
            return false;
        }

        if ($this->countSince(ActionKind::Reboot, self::DAY) >= RemediationLimits::MAX_REBOOTS_PER_24_HOURS) {
            $this->exhaust($situation);

            return false;
        }

        $this->serveEveryIncident(ActionKind::Reboot, Ladder::STEP_REBOOT, [LadderPath::Full]);

        return true;
    }

    private function wantReset(IncidentSituation $situation): bool
    {
        if ($this->resetsServing($situation) >= RemediationLimits::RESETS_PER_INCIDENT) {
            $this->exhaust($situation);

            return false;
        }

        if ($this->restartDecided || $this->inResetCooldown()) {
            return false;
        }

        if ($this->countSince(ActionKind::Reset, self::DAY) >= RemediationLimits::MAX_RESETS_PER_24_HOURS) {
            $this->hold($situation, LadderHold::ResetLimit);

            return false;
        }

        $this->serveEveryIncident(ActionKind::Reset, Ladder::STEP_RESET, [LadderPath::Full, LadderPath::ResetOnly]);

        return true;
    }

    /**
     * An instance-wide action serves every failing incident that may use that
     * step, and moves each up to it.
     *
     * @param list<LadderPath> $paths
     */
    private function serveEveryIncident(ActionKind $kind, int $step, array $paths): void
    {
        $served = array_values(array_filter(
            $this->situations,
            fn (IncidentSituation $situation): bool => $situation->failing
                && $situation->incident->exhaustedAt === null
                && in_array($situation->path, $paths, true),
        ));
        $this->addAction($kind, null, array_map(static fn (IncidentSituation $situation): int => $situation->id(), $served));

        foreach ($served as $situation) {
            $this->reach($situation, $step);
        }
    }

    private function resetsServing(IncidentSituation $situation): int
    {
        $count = 0;

        foreach ($this->actions as $action) {
            if ($action->kind === ActionKind::Reset && $action->serves($situation->id())) {
                $count++;
            }
        }

        return $count;
    }

    private function inResetCooldown(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->kind === ActionKind::Reset && $this->at - $action->decidedAt < RemediationLimits::RESET_COOLDOWN_SECONDS) {
                return true;
            }
        }

        return false;
    }

    private function countSince(ActionKind $kind, int $seconds): int
    {
        $count = 0;

        foreach ($this->actions as $action) {
            if ($action->kind === $kind && $this->at - $action->decidedAt < $seconds) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<int> $incidentIds
     */
    private function addAction(ActionKind $kind, ?string $unit, array $incidentIds): void
    {
        $action = Action::wouldRun($this->instance->instanceId, $kind, $unit, $this->at, $incidentIds);
        $this->actions[] = $action;
        $this->newActions[] = $action;
    }

    private function link(Action $action, IncidentSituation $situation): void
    {
        $linked = $action->linkedTo($situation->id());

        foreach ($this->actions as $i => $known) {
            if ($known === $action) {
                $this->actions[$i] = $linked;
            }
        }

        foreach ($this->newActions as $i => $known) {
            if ($known === $action) {
                $this->newActions[$i] = $linked;

                return;
            }
        }

        $this->changedActions[(int) $action->id] = $linked;
    }

    private function reach(IncidentSituation $situation, int $step): void
    {
        $this->incidents[$situation->id()] = $this->current($situation)->reachedStep($step);
    }

    private function exhaust(IncidentSituation $situation): void
    {
        $this->incidents[$situation->id()] = $this->current($situation)->exhausted($this->at);
    }

    private function hold(IncidentSituation $situation, LadderHold $hold): void
    {
        if ($situation->incident->ladderHold !== $hold) {
            $this->incidents[$situation->id()] = $this->current($situation)->heldBy($hold);
        }
    }

    private function current(IncidentSituation $situation): Incident
    {
        return $this->incidents[$situation->id()] ?? $situation->incident;
    }
}
