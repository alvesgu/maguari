<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The service check's fields in check settings and results (design section
 * 6.1.3). The server builds every sentence from these codes.
 */
final class ServiceCheck
{
    public const TYPE = 'service';

    /** Not active: the result's state says which ActiveState it is in. */
    public const REASON_INACTIVE = 'inactive';

    /** systemd has no such unit. */
    public const REASON_NOT_FOUND = 'not_found';

    /** systemctl could not be run or did not answer in time. */
    public const REASON_SYSTEMCTL_FAILED = 'systemctl_failed';

    /** ActiveState values that pass. */
    public const PASSING_STATES = ['active', 'reloading'];

    /** An ActiveState as systemctl prints it, for example "failed". */
    public const STATE_PATTERN = '/^[a-z-]{1,32}$/D';
}
