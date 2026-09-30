<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * An instance's status as Compute Engine reports it, in Fleet's own model
 * (design section 2.1 rule 4).
 */
enum InstanceStatus
{
    case Provisioning;
    case Staging;
    case Running;
    case Stopping;
    case Stopped;
    case Suspending;
    case Suspended;
    case Repairing;
    case Terminated;
    // A status Google added after this code was written.
    case Unknown;

    public static function fromComputeEngine(string $status): self
    {
        return match ($status) {
            'PROVISIONING' => self::Provisioning,
            'STAGING' => self::Staging,
            'RUNNING' => self::Running,
            'STOPPING' => self::Stopping,
            'STOPPED' => self::Stopped,
            'SUSPENDING' => self::Suspending,
            'SUSPENDED' => self::Suspended,
            'REPAIRING' => self::Repairing,
            'TERMINATED' => self::Terminated,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return $this->name;
    }
}
