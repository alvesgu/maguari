<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * A client check that cannot be added. The message is a fixed sentence for
 * the administrator.
 */
final class InvalidClientCheck extends \RuntimeException
{
}
