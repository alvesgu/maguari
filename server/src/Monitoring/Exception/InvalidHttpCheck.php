<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * A remote HTTP check that cannot be added or changed. The message is a
 * fixed sentence for the administrator.
 */
final class InvalidHttpCheck extends \RuntimeException
{
}
