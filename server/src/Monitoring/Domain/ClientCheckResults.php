<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
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
