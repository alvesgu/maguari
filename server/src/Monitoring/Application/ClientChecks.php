<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\ClientCheckSettings;
use Maguari\Server\Monitoring\Domain\ClientCheckStatus;
use Maguari\Server\Monitoring\Domain\DatabaseCheckRule;
use Maguari\Server\Monitoring\Domain\LocalHttpCheckRule;
use Maguari\Server\Monitoring\Domain\LocalHttpTarget;
use Maguari\Server\Monitoring\Domain\ServiceCheckRule;
use Maguari\Server\Monitoring\Domain\ServiceUnit;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Monitoring\Infrastructure\CheckStateRepository;
use Maguari\Server\Monitoring\Infrastructure\ClientCheckRepository;
use Maguari\Server\Monitoring\Infrastructure\FailureStreakRepository;
use Maguari\Server\Monitoring\Infrastructure\NotCheckedResultRepository;
use Maguari\Shared\DatabaseCheck;
use Maguari\Shared\LocalHttpCheck;
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
        private readonly NotCheckedResultRepository $notChecked,
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
                    $this->notChecked->find($instanceId, $check->checkName(), $check->subject()),
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
     * Like addService(), for a local HTTP check.
     *
     * @throws InvalidClientCheck
     */
    public function addLocalHttp(int $instanceId, string $url, string $hostName): ClientCheck
    {
        [$url, $hostName] = LocalHttpTarget::normalize($url, $hostName);
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $url, $hostName, $at): ClientCheck {
            $checks = array_filter($this->checks->forInstance($instanceId), static fn (ClientCheck $check): bool => $check->type === LocalHttpCheck::TYPE);

            foreach ($checks as $check) {
                if ($check->target === $url && $check->hostName === $hostName) {
                    throw new InvalidClientCheck(sprintf('%s is already checked on this instance.', $check->subject()));
                }
            }

            if (count($checks) >= ClientCheck::MAX_LOCAL_HTTP_PER_INSTANCE) {
                throw new InvalidClientCheck(sprintf('An instance can have at most %d local HTTP checks.', ClientCheck::MAX_LOCAL_HTTP_PER_INSTANCE));
            }

            return new ClientCheck($this->checks->add($instanceId, LocalHttpCheck::TYPE, $url, $hostName, $at), $instanceId, LocalHttpCheck::TYPE, $url, $hostName, $at);
        });
    }

    /**
     * Like addService(), for a database check: only the name of a database
     * set up on the instance, never how to log in to it.
     *
     * @throws InvalidClientCheck
     */
    public function addDatabase(int $instanceId, string $name): ClientCheck
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidClientCheck('Enter the name the database has on the instance, for example geonode.');
        }

        if (!DatabaseCheck::isValidName($name)) {
            throw new InvalidClientCheck('A database\'s name has 1 to 63 lowercase letters, digits, _ and -, starting with a letter or digit, as in maguari-client set-database on the instance.');
        }

        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceId, $name, $at): ClientCheck {
            $databases = array_filter($this->checks->forInstance($instanceId), static fn (ClientCheck $check): bool => $check->type === DatabaseCheck::TYPE);

            if (in_array($name, array_map(static fn (ClientCheck $check): string => $check->target, $databases), true)) {
                throw new InvalidClientCheck(sprintf('The database %s is already checked on this instance.', $name));
            }

            if (count($databases) >= ClientCheck::MAX_DATABASES_PER_INSTANCE) {
                throw new InvalidClientCheck(sprintf('An instance can have at most %d database checks.', ClientCheck::MAX_DATABASES_PER_INSTANCE));
            }

            return new ClientCheck($this->checks->add($instanceId, DatabaseCheck::TYPE, $name, '', $at), $instanceId, DatabaseCheck::TYPE, $name, '', $at);
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
            $configured[$check->checkName() . "\n" . $check->subject()] = $check;
        }

        foreach ($results->results as $result) {
            $check = $configured[$result->type . "\n" . $result->subject] ?? null;

            if ($check === null) {
                continue;
            }

            $this->checkStates->recordConfirmed(match ($result->type) {
                ServiceCheck::TYPE => ServiceCheckRule::result($instanceId, $result, $at),
                LocalHttpCheck::TYPE => LocalHttpCheckRule::result($check, $result, $at),
                DatabaseCheck::TYPE => DatabaseCheckRule::result($instanceId, $result, $at),
            });
        }
    }
}
