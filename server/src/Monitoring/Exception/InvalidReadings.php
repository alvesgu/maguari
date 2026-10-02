<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * A heartbeat's readings list that breaks the rules of design section 5.2.
 */
final class InvalidReadings extends \RuntimeException
{
}
