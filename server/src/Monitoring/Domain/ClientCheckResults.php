<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
use Maguari\Shared\DatabaseCheck;
use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\Protocol;
use Maguari\Shared\ServiceCheck;
use Maguari\Shared\UnitName;

/**
 * A heartbeat's `checks` (design section 5.2), validated before anything is
 * written, as readings are. A malformed result, an unknown reason for a known
 * type, the same check twice or too many results refuse the whole heartbeat.
 * Results of types this server does not know are left out, so newer clients
 * keep working.
 */
final class ClientCheckResults
{
    /**
     * @param list<ClientCheckResult> $results
     */
    private function __construct(
        public readonly array $results,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<mixed> $checks as decoded from JSON
     * @throws InvalidCheckResults
     */
    public static function parse(array $checks): self
    {
        if (count($checks) > Protocol::MAX_CHECK_RESULTS) {
            throw new InvalidCheckResults(sprintf('A heartbeat has at most %d check results.', Protocol::MAX_CHECK_RESULTS));
        }

        $results = [];
        $seen = [];

        foreach ($checks as $check) {
            if (!is_array($check) || array_is_list($check) || !is_string($check['type'] ?? null)) {
                throw new InvalidCheckResults('Each check result must be an object with a string type.');
            }

            $result = match ($check['type']) {
                ServiceCheck::TYPE => self::service($check),
                LocalHttpCheck::TYPE => self::localHttp($check),
                DatabaseCheck::TYPE => self::database($check),
                default => null,
            };

            if ($result === null) {
                continue;
            }

            $key = $result->type . "\n" . $result->subject;

            if (isset($seen[$key])) {
                throw new InvalidCheckResults(sprintf('The %s check of %s is reported twice.', $result->type, $result->subject));
            }

            $seen[$key] = true;
            $results[] = $result;
        }

        return new self($results);
    }

    /**
     * The subject is matched against the configured checks later, so here it
     * only has to look like one.
     *
     * @param array<string, mixed> $check
     */
    private static function localHttp(array $check): ClientCheckResult
    {
        $subject = $check['subject'] ?? null;
        $outcome = is_string($check['outcome'] ?? null) ? CheckOutcome::tryFrom($check['outcome']) : null;
        $reason = $check['reason'] ?? null;
        $status = $check['status'] ?? null;
        $ms = $check['ms'] ?? null;

        if (!is_string($subject) || strlen($subject) > LocalHttpCheck::MAX_URL_BYTES + 260 || preg_match('/[^ -~]/', $subject) === 1 || $outcome === null) {
            throw new InvalidCheckResults('A local HTTP result needs its check\'s URL as its subject and an outcome.');
        }

        $answered = is_int($status) && $status >= 100 && $status <= 599 && is_int($ms) && $ms >= 0 && $reason === null;
        $passes = $answered && $status < 400 && $status >= 200;
        $valid = match ($outcome) {
            CheckOutcome::Pass => $passes,
            CheckOutcome::Fail => ($answered && !$passes) || ($status === null && $ms === null && in_array($reason, LocalHttpCheck::REASONS, true)),
            CheckOutcome::NotChecked => $status === null && $ms === null && $reason === Protocol::CHECK_REASON_TIME_LIMIT,
        };

        if (!$valid) {
            throw new InvalidCheckResults(sprintf('The local HTTP result for %s has an outcome, status and reason that do not go together.', $subject));
        }

        return new ClientCheckResult(LocalHttpCheck::TYPE, $subject, $outcome, is_string($reason) ? $reason : null, null, $answered ? $status : null, $answered ? $ms : null);
    }

    /**
     * A database driver that is missing names its kind in `db_type`, kept
     * as the result's state, so the sentence names the package to install.
     *
     * @param array<string, mixed> $check
     */
    private static function database(array $check): ClientCheckResult
    {
        $subject = $check['subject'] ?? null;
        $outcome = is_string($check['outcome'] ?? null) ? CheckOutcome::tryFrom($check['outcome']) : null;
        $reason = $check['reason'] ?? null;
        $ms = $check['ms'] ?? null;
        $type = $check['db_type'] ?? null;

        if (!is_string($subject) || !DatabaseCheck::isValidName($subject) || $outcome === null) {
            throw new InvalidCheckResults('A database result needs a valid database name as its subject and an outcome.');
        }

        $valid = match ($outcome) {
            CheckOutcome::Pass => $reason === null && is_int($ms) && $ms >= 0,
            CheckOutcome::Fail => $ms === null && in_array($reason, DatabaseCheck::FAIL_REASONS, true),
            CheckOutcome::NotChecked => $ms === null && (in_array($reason, [DatabaseCheck::REASON_NOT_SET_UP, DatabaseCheck::REASON_FILE_UNUSABLE, Protocol::CHECK_REASON_TIME_LIMIT], true)
                || ($reason === DatabaseCheck::REASON_DRIVER_MISSING && isset(DatabaseCheck::DRIVERS[is_string($type) ? $type : '']))),
        };

        if (!$valid || ($type !== null && $reason !== DatabaseCheck::REASON_DRIVER_MISSING)) {
            throw new InvalidCheckResults(sprintf('The database result for %s has an outcome, time and reason that do not go together.', $subject));
        }

        return new ClientCheckResult(DatabaseCheck::TYPE, $subject, $outcome, is_string($reason) ? $reason : null, is_string($type) ? $type : null, null, is_int($ms) ? $ms : null);
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function service(array $check): ClientCheckResult
    {
        $subject = $check['subject'] ?? null;
        $outcome = is_string($check['outcome'] ?? null) ? CheckOutcome::tryFrom($check['outcome']) : null;
        $reason = $check['reason'] ?? null;
        $state = $check['state'] ?? null;

        if (!is_string($subject) || !UnitName::isValid($subject) || $outcome === null) {
            throw new InvalidCheckResults('A service result needs a valid unit name as its subject and an outcome.');
        }

        if ($state !== null && (!is_string($state) || preg_match(ServiceCheck::STATE_PATTERN, $state) !== 1)) {
            throw new InvalidCheckResults('A service result\'s state must be an ActiveState.');
        }

        $valid = match ($outcome) {
            CheckOutcome::Pass => $reason === null && in_array($state, ServiceCheck::PASSING_STATES, true),
            CheckOutcome::Fail => ($reason === ServiceCheck::REASON_INACTIVE && is_string($state) && !in_array($state, ServiceCheck::PASSING_STATES, true))
                || $reason === ServiceCheck::REASON_NOT_FOUND,
            CheckOutcome::NotChecked => in_array($reason, [ServiceCheck::REASON_SYSTEMCTL_FAILED, Protocol::CHECK_REASON_TIME_LIMIT], true),
        };

        if (!$valid) {
            throw new InvalidCheckResults(sprintf('The service result for %s has an outcome, reason and state that do not go together.', $subject));
        }

        return new ClientCheckResult(ServiceCheck::TYPE, $subject, $outcome, is_string($reason) ? $reason : null, is_string($state) ? $state : null);
    }
}
