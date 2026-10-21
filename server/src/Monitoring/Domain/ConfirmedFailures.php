<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The client's checks fail only after FAILURES_TO_FAIL failed results in a
 * row (design section 6.3 item 7), so one slow systemctl or a restart in
 * progress opens no incident. "Not checked" neither counts nor breaks a run.
 */
final class ConfirmedFailures
{
    public const FAILURES_TO_FAIL = 2;

    /**
     * Whether a failed result, the $failures-th in a row, changes the outcome.
     */
    public static function counts(int $failures): bool
    {
        return $failures >= self::FAILURES_TO_FAIL;
    }
}
