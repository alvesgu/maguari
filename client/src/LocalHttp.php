<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Requests a URL on the instance itself (design section 6.1.3).
 */
interface LocalHttp
{
    /**
     * @param string $url a URL that passes LocalHttpCheck::isValid() with $hostName
     * @param string $hostName '' for none; otherwise the Host header and TLS name
     * @return array{status: int, ms: int}|string the status line's code and the
     *         time until it arrived, or one of LocalHttpCheck's reasons
     */
    public function request(string $url, string $hostName): array|string;
}
