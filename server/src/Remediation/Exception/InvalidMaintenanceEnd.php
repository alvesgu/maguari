<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Exception;

/**
 * A maintenance end time that cannot be used. The message is a fixed sentence
 * for the administrator.
 */
final class InvalidMaintenanceEnd extends \RuntimeException
{
}
