<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\HttpProbe\HttpProbe;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailure;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailureKind;
use Maguari\Server\Kernel\Version;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\HttpAnswer;
use Maguari\Server\Monitoring\Domain\HttpCheck;
use Maguari\Server\Monitoring\Domain\HttpCheckEgress;
use Maguari\Server\Monitoring\Domain\HttpCheckRule;
use Maguari\Server\Monitoring\Domain\HttpCheckStatus;
use Maguari\Server\Monitoring\Domain\HttpCheckUrl;
use Maguari\Server\Monitoring\Domain\HttpFailure;
use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;
use Maguari\Server\Monitoring\Infrastructure\CheckStateRepository;
use Maguari\Server\Monitoring\Infrastructure\HttpCheckRepository;

/**
 * Remote HTTP checks of public web apps (design section 6.2.1): the ones
 * administrators set on the instance page, within the egress budget, and the
 * per-minute run that checks those due.
 */
final class HttpChecks
{
    /** No new check starts after this long, so a run ends within its minute. */
    public const RUN_TIME_LIMIT_SECONDS = 30;

    /**
     * Names the server's version, so a web app's logs show which Maguari asked.
     */
    public static function userAgent(): string
    {
        return 'Maguari/' . Version::current() . ' health check';
    }

    /**
     * @param \Closure(int): void $sleep waits that many seconds before a retry
     */
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly FleetApi $fleet,
        private readonly HttpCheckRepository $checks,
        private readonly CheckStateRepository $states,
        private readonly CheckStates $checkStates,
        private readonly HttpProbe $probe,
        private readonly \Closure $sleep,
    ) {
    }

    /**
     * @return list<HttpCheck> in URL order
     */
    public function forInstance(int $instanceId): array
    {
        return $this->checks->forInstance($instanceId);
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, list<HttpCheckStatus>> by instance ID, only instances with checks
     */
    public function statuses(array $instanceIds): array
    {
        $statuses = [];

        foreach ($instanceIds as $instanceId) {
            $states = [];

            foreach ($this->states->forInstance($instanceId, [HttpCheck::CHECK_NAME]) as $state) {
                $states[$state->subject] = $state;
            }

            foreach ($this->checks->forInstance($instanceId) as $check) {
                $statuses[$instanceId][] = new HttpCheckStatus($check, $states[$check->url] ?? null);
            }
        }

        return $statuses;
    }

    /**
     * The estimated monthly egress of every remote HTTP check together.
     */
    public function monthlyEgressBytes(): int
    {
        return HttpCheckEgress::totalMonthlyBytes($this->checks->intervals());
    }

    /**
     * Due at once. The count, the duplicate and the budget are checked in the
     * same transaction as the insert, so two submissions cannot both pass them.
     *
     * @param string $intervalMinutes as typed; empty for the default
     * @throws InvalidHttpCheck
     */
    public function add(int $instanceId, string $url, string $intervalMinutes): HttpCheck
    {
        $url = HttpCheckUrl::normalize($url);
        $intervalSeconds = HttpCheck::intervalSeconds($intervalMinutes);
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $url, $intervalSeconds, $at): HttpCheck {
            $existing = $this->checks->forInstance($instanceId);

            if (in_array($url, array_map(static fn (HttpCheck $check): string => $check->url, $existing), true)) {
                throw new InvalidHttpCheck(sprintf('%s is already checked for this instance.', $url));
            }

            if (count($existing) >= HttpCheck::MAX_PER_INSTANCE) {
                throw new InvalidHttpCheck(sprintf('An instance can have at most %d web app checks.', HttpCheck::MAX_PER_INSTANCE));
            }

            $this->refuseOverBudget([...$this->checks->intervals(), $intervalSeconds]);

            return new HttpCheck($this->checks->add($instanceId, $url, $intervalSeconds, $at), $instanceId, $url, $intervalSeconds, $at, $at);
        });
    }

    /**
     * A shorter interval brings the next check forward, never later than one
     * new interval from now.
     *
     * @return bool whether the check exists for that instance
     * @throws InvalidHttpCheck
     */
    public function changeInterval(int $instanceId, int $checkId, string $intervalMinutes): bool
    {
        $intervalSeconds = HttpCheck::intervalSeconds($intervalMinutes);
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $checkId, $intervalSeconds, $at): bool {
            $check = $this->checks->find($instanceId, $checkId);

            if ($check === null) {
                return false;
            }

            if ($intervalSeconds < $check->intervalSeconds) {
                $intervals = $this->checks->intervals();
                $intervals[array_search($check->intervalSeconds, $intervals, true)] = $intervalSeconds;
                $this->refuseOverBudget($intervals);
            }

            $this->checks->changeInterval($checkId, $intervalSeconds, min($check->nextDueAt, $at + $intervalSeconds));

            return true;
        });
    }

    /**
     * Withdraws the check's outcome at once, so its incident resolves:
     * removing it is the administrator's decision.
     *
     * @return bool whether it existed for that instance
     */
    public function remove(int $instanceId, int $checkId): bool
    {
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $checkId, $at): bool {
            $check = $this->checks->find($instanceId, $checkId);

            if ($check === null) {
                return false;
            }

            $this->checks->remove($instanceId, $checkId);
            $this->checkStates->withdraw($instanceId, HttpCheck::CHECK_NAME, $check->url, $at);

            return true;
        });
    }

    /**
     * Checks every due check of a picked instance, one after another and
     * outside any transaction; each result is stored in its own short
     * transaction, with its outcome change as an event. A failed request is
     * tried once more after HttpCheckRule::RETRY_AFTER_SECONDS. No new check
     * starts after RUN_TIME_LIMIT_SECONDS; the rest stay due for the next run.
     * Checks of instances that are no longer picked are skipped and
     * rescheduled.
     *
     * @return list<CheckResult> in the order checked
     */
    public function runDue(): array
    {
        $start = $this->clock->now();
        $picked = [];

        foreach ($this->fleet->pickedInstances() as $instance) {
            $picked[$instance->id] = true;
        }

        $results = [];

        foreach ($this->checks->due($start) as $check) {
            if ($this->clock->now() - $start >= self::RUN_TIME_LIMIT_SECONDS) {
                break;
            }

            if (!isset($picked[$check->instanceId])) {
                $this->checks->scheduleNext($check->id, $check->nextDueAfter($this->clock->now()));

                continue;
            }

            $answer = $this->request($check->url);
            $retried = false;

            if (!$answer->passes()) {
                ($this->sleep)(HttpCheckRule::RETRY_AFTER_SECONDS);
                $answer = $this->request($check->url);
                $retried = true;
            }

            $at = $this->clock->now();
            $result = HttpCheckRule::result($check, $answer, $at, $retried);

            $stored = $this->database->transaction(function () use ($check, $result, $at): bool {
                // Removed or replaced while it was being checked: its result belongs to nothing.
                if ($this->checks->find($check->instanceId, $check->id)?->url !== $check->url) {
                    return false;
                }

                $this->checkStates->record($result);
                $this->checks->scheduleNext($check->id, $check->nextDueAfter($at));

                return true;
            });

            if ($stored) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function request(string $url): HttpAnswer
    {
        try {
            $answer = $this->probe->probe($url, self::userAgent(), HttpCheckRule::CONNECT_TIMEOUT_SECONDS, HttpCheckRule::TIMEOUT_SECONDS);

            return HttpAnswer::status($answer->status, $answer->elapsedMs);
        } catch (HttpProbeFailure $failure) {
            return HttpAnswer::failure(match ($failure->kind) {
                HttpProbeFailureKind::NameNotResolved => HttpFailure::NameNotResolved,
                HttpProbeFailureKind::Refused => HttpFailure::Refused,
                HttpProbeFailureKind::TimedOut => HttpFailure::TimedOut,
                HttpProbeFailureKind::TlsFailed => HttpFailure::TlsFailed,
                HttpProbeFailureKind::ConnectFailed => HttpFailure::ConnectFailed,
                HttpProbeFailureKind::BadResponse => HttpFailure::BadResponse,
            });
        }
    }

    /**
     * @param int[] $intervalSeconds every check's interval after the change
     * @throws InvalidHttpCheck
     */
    private function refuseOverBudget(array $intervalSeconds): void
    {
        $bytes = HttpCheckEgress::totalMonthlyBytes($intervalSeconds);

        if ($bytes > HttpCheckEgress::MONTHLY_BUDGET_BYTES) {
            throw new InvalidHttpCheck(sprintf(
                'All web app checks together would send about %d MB a month, above the %d MB set aside for them. Use a longer interval or remove a check.',
                HttpCheckEgress::megabytes($bytes, roundUp: true),
                HttpCheckEgress::megabytes(HttpCheckEgress::MONTHLY_BUDGET_BYTES),
            ));
        }
    }
}
