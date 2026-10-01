<?php

declare(strict_types=1);

namespace Maguari\Server\Access\Exception;

/**
 * A server address that is not https://, or http:// on localhost, with no path.
 */
final class InvalidBaseUrl extends \RuntimeException
{
}
