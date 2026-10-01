<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Sends one HTTP request to the server. The client only ever sends POST
 * requests with a JSON body.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws ClientFailure when no complete response arrived
     */
    public function post(string $url, array $headers, string $body): TransportResponse;
}
