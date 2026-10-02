<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * Metric names in heartbeat readings (design section 5.2). A metric is
 * "<kind>:<subject>", for example "disk_used_bytes:/". A kind never contains
 * the separator, so splitting at the first one is unambiguous even for mount
 * points that contain it.
 */
final class Metric
{
    public const SEPARATOR = ':';

    /** Bytes used on a filesystem; the subject is its mount point. */
    public const DISK_USED_BYTES = 'disk_used_bytes';

    /** Total bytes of a filesystem; the subject is its mount point. */
    public const DISK_TOTAL_BYTES = 'disk_total_bytes';

    /**
     * When a certificate on the instance expires, in Unix seconds; the subject
     * is the certificate's first domain (design section 5.2).
     */
    public const CERTIFICATE_EXPIRES_AT = 'certificate_expires_at';

    /** The longest DNS name. */
    public const MAX_DOMAIN_BYTES = 253;

    public static function name(string $kind, string $subject): string
    {
        return $kind . self::SEPARATOR . $subject;
    }

    /**
     * Whether $domain is a certificate_expires_at subject: a lowercase DNS
     * name of letters, digits and hyphens, optionally a wildcard such as
     * "*.example.com". The client skips anything else and the server rejects
     * it.
     */
    public static function isCertificateDomain(string $domain): bool
    {
        return strlen($domain) <= self::MAX_DOMAIN_BYTES
            && preg_match('/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $domain) === 1;
    }

    /**
     * @return array{string, ?string} the kind and the subject, which is null
     *         when the name has no separator
     */
    public static function split(string $metric): array
    {
        $position = strpos($metric, self::SEPARATOR);

        if ($position === false) {
            return [$metric, null];
        }

        return [substr($metric, 0, $position), substr($metric, $position + 1)];
    }
}
