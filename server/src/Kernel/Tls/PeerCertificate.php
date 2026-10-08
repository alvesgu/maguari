<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Tls;

/**
 * What Maguari reads from a served certificate.
 */
final class PeerCertificate
{
    /**
     * @param int $expiresAt the certificate's last valid second
     */
    public function __construct(
        public readonly int $expiresAt,
    ) {
    }
}
