<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The egress remote HTTP checks cost the server (design section 14). Only
 * what the server sends counts: TCP, the TLS ClientHello and Finished, the
 * request and the close. Estimates use a 31-day month. Like the sliders of
 * design section 7.3, the budget is a soft limit that warns and the limit a
 * hard one that refuses.
 */
final class HttpCheckEgress
{
    /**
     * More than what one check measured in development sends, about 1.5 KB
     * (design section 6.2.1).
     */
    public const BYTES_PER_CHECK = 2048;

    /** Suggested for all remote HTTP checks together, per month: above it, a change saves with a warning. */
    public const MONTHLY_BUDGET_BYTES = 200_000_000;

    /**
     * Never more for all remote HTTP checks together, per month: with the
     * heartbeats of 10 instances (about 430 MB) the free tier's 1 GB still holds.
     */
    public const MONTHLY_LIMIT_BYTES = 500_000_000;

    public const MONTH_SECONDS = 31 * 86_400;

    public static function monthlyBytes(int $intervalSeconds): int
    {
        return (int) ceil(self::MONTH_SECONDS / $intervalSeconds) * self::BYTES_PER_CHECK;
    }

    /**
     * @param int[] $intervalSeconds one per check
     */
    public static function totalMonthlyBytes(array $intervalSeconds): int
    {
        return array_sum(array_map(self::monthlyBytes(...), $intervalSeconds));
    }

    /**
     * The shortest interval, in whole minutes, at which one more check keeps
     * the total within $limitBytes; null when not even the longest does.
     *
     * @param int $otherBytes the other checks' monthly total
     */
    public static function shortestIntervalMinutes(int $otherBytes, int $limitBytes): ?int
    {
        for ($minutes = HttpCheck::MIN_INTERVAL_MINUTES; $minutes <= HttpCheck::MAX_INTERVAL_MINUTES; $minutes++) {
            if ($otherBytes + self::monthlyBytes($minutes * 60) <= $limitBytes) {
                return $minutes;
            }
        }

        return null;
    }

    /**
     * Whole megabytes (10^6 bytes), rounded; rounded up above a limit, so it
     * never names a total that looks within it.
     */
    public static function megabytes(int $bytes, bool $roundUp = false): int
    {
        return (int) ($roundUp ? ceil($bytes / 1_000_000) : round($bytes / 1_000_000));
    }

    /**
     * The sentence for a change that takes the total above the budget or the
     * limit, with the shortest interval that would fit, or null when within both.
     *
     * @param int $otherBytes the other checks' monthly total
     * @param int $intervalSeconds the changed check's new interval
     */
    public static function excess(int $otherBytes, int $intervalSeconds): ?EgressExcess
    {
        $total = $otherBytes + self::monthlyBytes($intervalSeconds);
        $over = match (true) {
            $total > self::MONTHLY_LIMIT_BYTES => self::MONTHLY_LIMIT_BYTES,
            $total > self::MONTHLY_BUDGET_BYTES => self::MONTHLY_BUDGET_BYTES,
            default => null,
        };

        if ($over === null) {
            return null;
        }

        $refused = $over === self::MONTHLY_LIMIT_BYTES;
        $fits = self::shortestIntervalMinutes($otherBytes, $over);
        $fitting = $fits === null
            ? ($refused ? ' Even an interval of 60 minutes would not fit: remove another check first.' : ' Even an interval of 60 minutes would not stay within it, so consider removing a check.')
            // Never 1: the change itself is at least that long and does not fit.
            : sprintf(' An interval of %d minutes or more would %s.', $fits, $refused ? 'fit' : 'stay within it');

        return new EgressExcess($refused, sprintf(
            $refused
                ? 'Not saved: all web app checks together would send about %d MB a month, above the limit of %d MB that keeps the free tier\'s 1 GB safe.%s'
                : 'Saved, but all web app checks together now send about %d MB a month, above the %d MB suggested for them.%s',
            self::megabytes($total, roundUp: true),
            self::megabytes($over),
            $fitting,
        ));
    }
}
