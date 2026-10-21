<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;

/**
 * A public web app the server requests on a fixed interval (design section
 * 6.2.1), as an administrator set it for one instance. Its URL is the check
 * results' subject.
 */
final class HttpCheck
{
    public const CHECK_NAME = 'remote_http';

    /** Bounds the work per instance; the egress budget bounds the fleet. */
    public const MAX_PER_INSTANCE = 10;

    public const MIN_INTERVAL_MINUTES = 1;
    public const MAX_INTERVAL_MINUTES = 60;
    public const DEFAULT_INTERVAL_MINUTES = 10;

    /**
     * @param int $instanceId Fleet's instance ID
     * @param int $nextDueAt when the next run should check it
     */
    public function __construct(
        public readonly int $id,
        public readonly int $instanceId,
        public readonly string $url,
        public readonly int $intervalSeconds,
        public readonly int $addedAt,
        public readonly int $nextDueAt,
    ) {
    }

    public function intervalMinutes(): int
    {
        return intdiv($this->intervalSeconds, 60);
    }

    /**
     * The interval in seconds from the minutes typed in the form; empty
     * means the default.
     *
     * @throws InvalidHttpCheck
     */
    public static function intervalSeconds(string $minutes): int
    {
        $minutes = trim($minutes);

        if ($minutes === '') {
            return self::DEFAULT_INTERVAL_MINUTES * 60;
        }

        if (preg_match('/^[0-9]{1,2}$/D', $minutes) !== 1 || (int) $minutes < self::MIN_INTERVAL_MINUTES || (int) $minutes > self::MAX_INTERVAL_MINUTES) {
            throw new InvalidHttpCheck(sprintf('Enter an interval of %d to %d minutes.', self::MIN_INTERVAL_MINUTES, self::MAX_INTERVAL_MINUTES));
        }

        return (int) $minutes * 60;
    }

    /**
     * The first due time after $at on this check's schedule: slots that
     * already passed are skipped, so a late run never checks twice in a row.
     */
    public function nextDueAfter(int $at): int
    {
        $next = $this->nextDueAt;

        do {
            $next += $this->intervalSeconds;
        } while ($next <= $at);

        return $next;
    }
}
