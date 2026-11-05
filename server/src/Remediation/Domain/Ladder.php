<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

use Maguari\Shared\DatabaseCheck;
use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\ServiceCheck;

/**
 * Which part of the escalation ladder each check may use (design section
 * 7.1). A check that is not named here is alert only.
 */
final class Ladder
{
    public const STEP_NONE = 0;
    public const STEP_RESTART = 1;
    public const STEP_RESTART_AGAIN = 2;
    public const STEP_REBOOT = 3;
    public const STEP_RESET = 4;

    /** Monitoring's name for the heartbeat-age check. */
    public const HEARTBEAT_AGE = 'heartbeat_age';

    /**
     * The unit a failing check restarts: a service check's own, or the one
     * an administrator associated with a local HTTP or database check.
     */
    public static function unit(string $checkName, string $subject, ?string $associated): ?string
    {
        return match ($checkName) {
            ServiceCheck::TYPE => $subject,
            LocalHttpCheck::TYPE, DatabaseCheck::TYPE => $associated,
            default => null,
        };
    }

    /**
     * @param ?string $unit what unit() returned
     * @param ?string $reason the check's latest reason code for failing
     */
    public static function path(string $checkName, ?string $unit, ?string $reason): LadderPath
    {
        if ($checkName === self::HEARTBEAT_AGE) {
            return LadderPath::ResetOnly;
        }

        if ($unit === null || $reason === ServiceCheck::REASON_NOT_FOUND || $reason === DatabaseCheck::REASON_LOGIN_REFUSED) {
            return LadderPath::AlertOnly;
        }

        return LadderPath::Full;
    }

    public static function stepLabel(int $step): string
    {
        return match ($step) {
            self::STEP_RESTART => 'Restart',
            self::STEP_RESTART_AGAIN => 'Restart again',
            self::STEP_REBOOT => 'Reboot',
            self::STEP_RESET => 'Reset',
            default => 'No action yet',
        };
    }
}
