<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpClient;

interface HttpClient
{
    /**
     * Sends the request and returns the response, whatever its status. Redirects
     * are not followed.
     *
     * @throws HttpClientFailure when no response arrives (connection, TLS or timeout)
     */
    public function send(HttpRequest $request): HttpResponse;
}
