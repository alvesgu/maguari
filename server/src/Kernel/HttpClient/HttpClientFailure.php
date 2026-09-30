<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpClient;

/**
 * No response arrived: the connection failed, TLS failed or a timeout passed.
 */
final class HttpClientFailure extends \RuntimeException
{
}
