<?php

declare(strict_types=1);

namespace Maguari\Server\Clients\Exception;

/**
 * The enrollment token is unknown, already used or expired. The three are not told apart.
 */
final class InvalidEnrollmentToken extends \RuntimeException
{
}
