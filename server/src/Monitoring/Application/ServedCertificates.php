<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Kernel\Tls\TlsCertificateReader;
use Maguari\Server\Kernel\Tls\TlsFailure;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Domain\ServedCertificate;

/**
 * Reads the certificate a hostname serves (design section 6.2): first over a
 * verified connection; if that fails, over a second one without
 * verification, only to read the certificate, so an expired one still shows
 * its date.
 */
final class ServedCertificates
{
    /** For connecting and the handshake, per connection. */
    public const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly TlsCertificateReader $reader,
    ) {
    }

    /**
     * @return ?ServedCertificate null when neither connection worked
     */
    public function read(string $hostname): ?ServedCertificate
    {
        try {
            return new ServedCertificate($this->reader->read($hostname, CertificateHostname::PORT, true, self::TIMEOUT_SECONDS)->expiresAt, true);
        } catch (TlsFailure) {
            // Not trusted, a wrong name, expired, or no connection at all.
        }

        try {
            return new ServedCertificate($this->reader->read($hostname, CertificateHostname::PORT, false, self::TIMEOUT_SECONDS)->expiresAt, false);
        } catch (TlsFailure) {
            return null;
        }
    }
}
