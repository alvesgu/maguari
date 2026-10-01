<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * A newly enrolled client's credentials. The plain secret exists only here, to
 * be sent to the client once; the database holds it encrypted.
 */
final class EnrolledClient
{
    public function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter]
        public readonly string $secret,
    ) {
    }
}
