<?php

declare(strict_types=1);

namespace Maguari\Server\Access\Exception;

/**
 * Setup is already complete, or the setup token is missing, unknown or expired.
 */
final class SetupNotAllowed extends \RuntimeException
{
}
