<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Exception;

/**
 * The Compute Engine API has no instance with this zone and name in the project.
 */
final class InstanceNotFound extends \RuntimeException
{
}
