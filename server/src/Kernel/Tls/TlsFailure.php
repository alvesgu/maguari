<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Tls;

/**
 * No connection, no handshake or no acceptable certificate. The message is
 * for logs and tests only: pages show Maguari's own sentences.
 */
final class TlsFailure extends \RuntimeException
{
}
