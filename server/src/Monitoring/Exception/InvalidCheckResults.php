<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * A heartbeat's check results that break the protocol's rules (design
 * section 5.2), so the whole heartbeat is refused.
 */
final class InvalidCheckResults extends \InvalidArgumentException
{
}
