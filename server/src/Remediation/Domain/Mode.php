<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * An instance's remediation mode (design section 7.2 item 8). Maintenance is
 * kept apart, like a snooze over the mode: when it ends, the instance is back
 * in the mode it had.
 */
enum Mode: string
{
    case Automatic = 'automatic';
    case AlertOnly = 'alert_only';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::AlertOnly => 'Alert-only',
        };
    }
}
