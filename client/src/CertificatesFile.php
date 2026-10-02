<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Where the certificate scanner writes the certificates' expiry dates and the
 * client reads them (design section 6.1.1). The directory belongs to root, not
 * to the client, so root never writes into a directory an unprivileged user
 * controls.
 */
final class CertificatesFile
{
    public const DEFAULT_PATH = '/var/lib/maguari-certificate-scanner/certificates.json';

    /**
     * MAGUARI_CERTIFICATES_FILE overrides the path for development and tests
     * only, for both the scanner and the client.
     */
    public static function pathFromEnvironment(): string
    {
        $path = getenv('MAGUARI_CERTIFICATES_FILE');

        return is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH;
    }
}
