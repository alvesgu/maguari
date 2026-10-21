<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The egress remote HTTP checks cost the server (design section 14). Only
 * what the server sends counts: TCP, the TLS ClientHello and Finished, the
 * request and the close. Estimates use a 31-day month.
 */
final class HttpCheckEgress
{
    /**
     * More than what one check measured in development sends, for
     * retransmissions and larger ClientHellos (design section 6.2.1).
     */
    public const BYTES_PER_CHECK = 4096;

    /** All remote HTTP checks together, per month, out of the free tier's 1 GB. */
    public const MONTHLY_BUDGET_BYTES = 200_000_000;

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
     * Whole megabytes (10^6 bytes), rounded; rounded up for a refusal, so it
     * never names a total that looks within the budget.
     */
    public static function megabytes(int $bytes, bool $roundUp = false): int
    {
        return (int) ($roundUp ? ceil($bytes / 1_000_000) : round($bytes / 1_000_000));
    }
}
