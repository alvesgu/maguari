<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\ClientCheckSettings;
use Maguari\Server\Monitoring\Domain\ClientCheckStatus;
use Maguari\Server\Monitoring\Domain\ServiceCheckRule;
use Maguari\Server\Monitoring\Domain\ServiceUnit;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Monitoring\Infrastructure\CheckStateRepository;
use Maguari\Server\Monitoring\Infrastructure\ClientCheckRepository;
use Maguari\Server\Monitoring\Infrastructure\FailureStreakRepository;
use Maguari\Shared\ServiceCheck;

/**
 * The checks each instance's client runs (design section 6.1.3): defined on
 * the instance page, sent to the client as versioned settings, and judged
 * from the results its heartbeats carry.
 */
final class ClientChecks
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly ClientCheckRepository $checks,
        private readonly CheckStateRepository $states,
        private readonly FailureStreakRepository $streaks,
        private readonly CheckStates $checkStates,
    ) {
    }

    /**
     * @return list<ClientCheck>
     */
    public function forInstance(int $instanceId): array
    {
        return $this->checks->forInstance($instanceId);
    }

    public function settings(int $instanceId): ClientCheckSettings
    {
        return ClientCheckSettings::of($this->checks->forInstance($instanceId));
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, list<ClientCheckStatus>> by instance ID, only instances with checks
     */
    public function statuses(array $instanceIds): array
    {
        $statuses = [];

        foreach ($instanceIds as $instanceId) {
            foreach ($this->checks->forInstance($instanceId) as $check) {
                $statuses[$instanceId][] = new ClientCheckStatus(
                    $check,
                    $this->states->find($instanceId, $check->checkName(), $check->subject()),
                    $this->streaks->failures($instanceId, $check->checkName(), $check->subject()),
                );
            }
        }

        return $statuses;
    }

    /**
     * The count and the duplicate are checked in the same transaction as the
     * insert, so two submissions cannot both pass them. The client gets it
     * with its next heartbeat's response.
     *
     * @throws InvalidClientCheck
     */
    public function addService(int $instanceId, string $unit): ClientCheck
    {
        $unit = ServiceUnit::normalize($unit);
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $unit, $at): ClientCheck {
            $services = array_filter($this->checks->forInstance($instanceId), static fn (ClientCheck $check): bool => $check->type === ServiceCheck::TYPE);

            if (in_array($unit, array_map(static fn (ClientCheck $check): string => $check->target, $services), true)) {
                throw new InvalidClientCheck(sprintf('%s is already checked on this instance.', $unit));
            }

            if (count($services) >= ClientCheck::MAX_SERVICES_PER_INSTANCE) {
                throw new InvalidClientCheck(sprintf('An instance can have at most %d service checks.', ClientCheck::MAX_SERVICES_PER_INSTANCE));
            }

            return new ClientCheck($this->checks->add($instanceId, ServiceCheck::TYPE, $unit, '', $at), $instanceId, ServiceCheck::TYPE, $unit, '', $at);
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
            $this->checkStates->withdraw($instanceId, $check->checkName(), $check->subject(), $at);

            return true;
        });
    }

    /**
     * Judges a heartbeat's results, inside the transaction that records the
     * heartbeat. Results for checks the instance no longer has are ignored:
     * the client may be a heartbeat behind a removal.
     */
    public function record(int $instanceId, int $at, ClientCheckResults $results): void
    {
        if ($results->results === []) {
            return;
        }

        $configured = [];

        foreach ($this->checks->forInstance($instanceId) as $check) {
            $configured[$check->checkName() . "\n" . $check->subject()] = true;
        }

        foreach ($results->results as $result) {
            if (!isset($configured[$result->type . "\n" . $result->subject])) {
                continue;
            }

            $this->checkStates->recordConfirmed(match ($result->type) {
                ServiceCheck::TYPE => ServiceCheckRule::result($instanceId, $result, $at),
            });
        }
    }
}
