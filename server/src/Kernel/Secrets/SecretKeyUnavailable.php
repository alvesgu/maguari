<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Secrets;

/**
 * The secret key file cannot be created or read. The message names the path.
 */
final class SecretKeyUnavailable extends \RuntimeException
{
}
