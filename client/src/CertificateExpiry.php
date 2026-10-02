<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Metric;

/**
 * The expiry date of each certificate on the instance, as heartbeat readings
 * (design section 5.2), from the file the certificate scanner writes. The
 * client never reads /etc/letsencrypt. A missing or malformed file gives no
 * readings and never stops a heartbeat.
 */
final class CertificateExpiry
{
    public const MAX_CERTIFICATES = 20;

    public function __construct(
        private readonly string $path,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(CertificatesFile::pathFromEnvironment());
    }

    /**
     * One reading per first domain. Two certbot lineages can share it (for
     * example example.com and example.com-0001), and the server rejects a
     * metric sent twice, so the latest expiry wins: it is the certificate in
     * use, and the older one would only fail falsely.
     *
     * @return list<array{metric: string, value: int}>
     */
    public function readings(): array
    {
        $contents = @file_get_contents($this->path, false, null, 0, CertificateScanner::MAX_FILE_BYTES);

        if (!is_string($contents)) {
            return [];
        }

        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $certificates = is_array($data) ? ($data['certificates'] ?? null) : null;
        /** @var array<string, int> $latest expiry by domain; keys may become ints */
        $latest = [];

        foreach (is_array($certificates) ? $certificates : [] as $certificate) {
            $domains = is_array($certificate) ? ($certificate['domains'] ?? null) : null;
            $domain = is_array($domains) ? ($domains[0] ?? null) : null;
            $expiresAt = is_array($certificate) ? ($certificate['expires_at'] ?? null) : null;

            if (!is_string($domain) || !is_int($expiresAt) || $expiresAt < 0) {
                continue;
            }

            $domain = strtolower($domain);

            if (!Metric::isCertificateDomain($domain) || (!isset($latest[$domain]) && count($latest) === self::MAX_CERTIFICATES)) {
                continue;
            }

            $latest[$domain] = max($latest[$domain] ?? 0, $expiresAt);
        }

        $readings = [];

        foreach ($latest as $domain => $expiresAt) {
            $readings[] = ['metric' => Metric::name(Metric::CERTIFICATE_EXPIRES_AT, (string) $domain), 'value' => $expiresAt];
        }

        return $readings;
    }
}
