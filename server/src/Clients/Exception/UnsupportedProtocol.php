<?php

declare(strict_types=1);

namespace Maguari\Server\Clients\Exception;

/**
 * The client speaks a protocol version the server does not support (design section 4 item 5).
 */
final class UnsupportedProtocol extends \RuntimeException
{
}
