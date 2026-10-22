<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\CheckState;
use Maguari\Server\Monitoring\Domain\ConfirmedFailures;
use Maguari\Server\Monitoring\Domain\HeartbeatAgeRule;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Monitoring\Event\CheckPassed;
use Maguari\Server\Monitoring\Event\CheckWithdrawn;
use Maguari\Server\Monitoring\Infrastructure\CheckStateRepository;
use Maguari\Server\Monitoring\Infrastructure\FailureStreakRepository;
use Maguari\Server\Monitoring\Infrastructure\NotCheckedResultRepository;

/**
 * Keeps each check's current outcome and writes an event when it changes
 * (design section 6.3), never for a repeat or "not checked". Every method
 * must run inside the transaction that stores the change, which the outbox
 * enforces. Nothing is delivered here: the tick does that.
 */
final class CheckStates
{
    public function __construct(
        private readonly CheckStateRepository $states,
        private readonly EventOutbox $outbox,
        private readonly FailureStreakRepository $streaks,
        private readonly NotCheckedResultRepository $notChecked,
    ) {
    }

    /**
     * A repeat of the same outcome only keeps its sentence, the latest, and
     * writes no event.
     */
    public function record(CheckResult $result): void
    {
        $current = $this->states->find($result->instanceId, $result->checkName, $result->subject);
        $state = CheckState::after($current, $result);

        if ($state === null || $state == $current) {
            return;
        }

        $this->states->save($state);

        if (!$state->changedFrom($current)) {
            return;
        }

        $event = $state->outcome === CheckOutcome::Fail ? CheckFailed::fromResult($result) : CheckPassed::fromResult($result);
        $this->outbox->record($event->toEvent());
    }

    /**
     * Like record(), for checks whose failures must be confirmed (design
     * section 6.3 item 7): a failed result changes a passing or new outcome
     * only as the ConfirmedFailures::FAILURES_TO_FAIL-th in a row. Until then
     * it is only counted, and the outcome keeps its sentence and time. A pass
     * ends the run; "not checked" neither counts nor ends it, and is only
     * kept, the latest, for pages until the next pass or fail.
     */
    public function recordConfirmed(CheckResult $result): void
    {
        $key = [$result->instanceId, $result->checkName, $result->subject];

        if ($result->outcome === CheckOutcome::NotChecked) {
            $this->notChecked->save($result);

            return;
        }

        $this->notChecked->delete(...$key);

        if ($result->outcome === CheckOutcome::Fail && $this->states->find(...$key)?->outcome !== CheckOutcome::Fail) {
            if (!ConfirmedFailures::counts($this->streaks->increment(...$key))) {
                return;
            }

            $this->streaks->delete(...$key);
        } elseif ($result->outcome === CheckOutcome::Pass) {
            $this->streaks->delete(...$key);
        }

        $this->record($result);
    }

    /**
     * Forgets a subject that is gone, writing check_withdrawn if it had an
     * outcome.
     */
    public function withdraw(int $instanceId, string $checkName, string $subject, int $at): void
    {
        $this->streaks->delete($instanceId, $checkName, $subject);
        $this->notChecked->delete($instanceId, $checkName, $subject);

        if ($this->states->delete($instanceId, $checkName, $subject)) {
            $this->outbox->record((new CheckWithdrawn($instanceId, $checkName, $subject, $at))->toEvent());
        }
    }

    /**
     * After a successful daily run: withdraws the subjects of $checkNames
     * that have an outcome but got no result for the instance in this run,
     * because the instance no longer reports them. Only while the instance's
     * heartbeat-age check passes: a silent instance reports nothing, and its
     * missing results count as not checked.
     *
     * @param string[] $checkNames
     * @param CheckResult[] $results the instance's results in the run
     */
    public function withdrawMissing(int $instanceId, array $checkNames, array $results, int $at): void
    {
        if ($this->states->find($instanceId, HeartbeatAgeRule::CHECK_NAME, '')?->outcome !== CheckOutcome::Pass) {
            return;
        }

        $present = [];

        foreach ($results as $result) {
            $present[$result->checkName][$result->subject] = true;
        }

        foreach ($this->states->forInstance($instanceId, $checkNames) as $state) {
            if (!isset($present[$state->checkName][$state->subject])) {
                $this->withdraw($instanceId, $state->checkName, $state->subject, $at);
            }
        }
    }
}
