<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Kernel\Tls\PeerCertificate;
use Maguari\Server\Kernel\Tls\TlsCertificateReader;
use Maguari\Server\Kernel\Tls\TlsFailure;

/**
 * Serves certificates by hostname without the network. A hostname not set
 * up does not connect.
 */
final class FakeTlsCertificateReader implements TlsCertificateReader
{
    /** @var array<string, array{int, bool}> expiry and trust, by hostname */
    private array $certificates = [];

    /** @var list<array{string, int, bool, float}> host, port, verify, timeout */
    public array $reads = [];

    /**
     * @param bool $trusted false for a certificate that fails verification
     *        (untrusted, a wrong name or expired), which only an unverified
     *        connection reads
     */
    public function serve(string $hostname, int $expiresAt, bool $trusted = true): void
    {
        $this->certificates[$hostname] = [$expiresAt, $trusted];
    }

    public function read(string $host, int $port, bool $verify, float $timeoutSeconds): PeerCertificate
    {
        $this->reads[] = [$host, $port, $verify, $timeoutSeconds];
        [$expiresAt, $trusted] = $this->certificates[$host] ?? throw new TlsFailure('No connection to ' . $host);

        if ($verify && !$trusted) {
            throw new TlsFailure('The TLS handshake with ' . $host . ' failed.');
        }

        return new PeerCertificate($expiresAt);
    }
}
