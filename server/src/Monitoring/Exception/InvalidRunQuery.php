<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * Parameters for reading runs that break the rules of design section 9.1.
 * The message is a fixed sentence for the administrator.
 */
final class InvalidRunQuery extends \RuntimeException
{
}
