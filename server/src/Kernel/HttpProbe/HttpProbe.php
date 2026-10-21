<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpProbe;

/**
 * Sends one GET and reads only the response's status line, for checks that
 * ask whether a site answers. The body is never read, which keeps both the
 * time and the ACKs sent back (egress) small. Redirects are not followed.
 */
interface HttpProbe
{
    /**
     * @param string $url an http:// or https:// URL with a DNS name as its host
     * @param float $connectSeconds limit for connecting and the TLS handshake together
     * @param float $totalSeconds limit until the status line has arrived
     * @throws HttpProbeFailure when no status line arrives
     */
    public function probe(string $url, string $userAgent, float $connectSeconds, float $totalSeconds): HttpProbeAnswer;
}
