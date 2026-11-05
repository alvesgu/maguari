<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * The remediation settings (design section 7.3): named constants with the
 * design's defaults until the settings hierarchy of phase 5 (section 18 item
 * 3).
 */
final class RemediationLimits
{
    /** An incident must be open this long, and its check still failing, before any action. */
    public const ACT_AFTER_SECONDS = 180;

    /** No action on an instance for this long after a reboot or a reset. */
    public const BOOT_GRACE_SECONDS = 420;

    /** Guards a new incident against a restart of the same unit that just happened. */
    public const RESTART_COOLDOWN_SECONDS = 300;

    /** Between resets of different incidents on one instance. */
    public const RESET_COOLDOWN_SECONDS = 600;

    public const MAX_RESTARTS_PER_HOUR = 3;
    public const MAX_RESETS_PER_24_HOURS = 3;
    public const MAX_REBOOTS_PER_24_HOURS = 3;

    /** After this many, the incident's ladder is exhausted. */
    public const RESETS_PER_INCIDENT = 1;

    /** The furthest ahead a maintenance can end. */
    public const MAX_MAINTENANCE_SECONDS = 30 * 86_400;
}
