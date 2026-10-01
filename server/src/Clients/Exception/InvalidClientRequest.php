<?php

declare(strict_types=1);

namespace Maguari\Server\Clients\Exception;

/**
 * A client request body that is not valid JSON or lacks a required field.
 */
final class InvalidClientRequest extends \RuntimeException
{
}
