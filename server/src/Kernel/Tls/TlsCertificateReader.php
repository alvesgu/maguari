<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Tls;

/**
 * Connects to a TLS server and reads the certificate it serves.
 */
interface TlsCertificateReader
{
    /**
     * @param bool $verify whether the certificate must be trusted and match
     *        $host; when false, any certificate is read
     * @param float $timeoutSeconds for connecting and the handshake together
     * @throws TlsFailure when no connection, no handshake or (with $verify)
     *         no trusted certificate for $host
     */
    public function read(string $host, int $port, bool $verify, float $timeoutSeconds): PeerCertificate;
}
