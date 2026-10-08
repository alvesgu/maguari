<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;

/**
 * A hostname whose served certificate the daily job checks, on port 443
 * (design section 6.2), as an administrator entered it for one instance.
 */
final class CertificateHostname
{
    public const PORT = 443;

    /** Bounds the daily job's time: each hostname is one or two TLS connections. */
    public const MAX_PER_INSTANCE = 10;

    private const MAX_BYTES = 253;
    private const MAX_LABEL_BYTES = 63;

    /**
     * @param int $instanceId Fleet's instance ID
     */
    public function __construct(
        public readonly int $id,
        public readonly int $instanceId,
        public readonly string $hostname,
        public readonly int $addedAt,
    ) {
    }

    /**
     * The hostname as stored: trimmed and lowercased. Every rejection has
     * its own sentence.
     *
     * @throws InvalidCertificateHostname
     */
    public static function normalize(string $input): string
    {
        $hostname = strtolower(trim($input));

        if ($hostname === '') {
            throw new InvalidCertificateHostname('Enter a hostname.');
        }

        if (preg_match('/[^\x20-\x7e]/', $hostname) === 1) {
            throw new InvalidCertificateHostname('Enter internationalized names in their xn-- form, as in the certificate.');
        }

        if (preg_match('#[:/\s?@]#', $hostname) === 1) {
            throw new InvalidCertificateHostname('Enter only the hostname, for example www.example.com, without https://, a port or a path.');
        }

        if (str_contains($hostname, '*')) {
            throw new InvalidCertificateHostname('Enter a hostname the certificate covers, for example www.example.com, not a wildcard.');
        }

        $labels = explode('.', $hostname);

        if (count($labels) < 2) {
            throw new InvalidCertificateHostname('Enter a full hostname with its domain, for example www.example.com.');
        }

        if (preg_match('/^[0-9]+$/D', $labels[count($labels) - 1]) === 1) {
            throw new InvalidCertificateHostname('Enter a hostname, not an IP address.');
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_BYTES || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                throw new InvalidCertificateHostname('That is not a valid hostname: use letters, digits, hyphens and dots, for example www.example.com.');
            }
        }

        if (strlen($hostname) > self::MAX_BYTES) {
            throw new InvalidCertificateHostname(sprintf('A hostname has at most %d characters.', self::MAX_BYTES));
        }

        return $hostname;
    }

    /**
     * Whether $hostname could be added, for suggestions.
     */
    public static function isValid(string $hostname): bool
    {
        try {
            return self::normalize($hostname) === $hostname;
        } catch (InvalidCertificateHostname) {
            return false;
        }
    }
}
