<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * What enrollment gives the client: the server's address, its client ID and
 * its HMAC secret (design section 5.6).
 */
final class Credentials
{
    public function __construct(
        public readonly string $serverUrl,
        public readonly string $clientId,
        #[\SensitiveParameter]
        public readonly string $secret,
    ) {
    }
}
