<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Exception;

/**
 * A zone or instance name that does not have Compute Engine's format.
 */
final class InvalidInstanceName extends \RuntimeException
{
}
