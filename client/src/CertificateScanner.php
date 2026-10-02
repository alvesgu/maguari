<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * The certificate scanner (design section 6.1.1). It runs as root, reads only
 * the cert.pem of each certbot lineage under /etc/letsencrypt/live/ and writes
 * each certificate's domains and expiry date, which are public, to a file the
 * client can read. It never reads private keys.
 */
final class CertificateScanner
{
    public const DEFAULT_LETSENCRYPT_DIRECTORY = '/etc/letsencrypt';

    public const MAX_CERTIFICATES = 20;

    /** A certificate is about 2 KiB; anything far larger is not one. */
    public const MAX_FILE_BYTES = 65536;

    private const PEM_CERTIFICATE = '/-----BEGIN CERTIFICATE-----\R[A-Za-z0-9+\/=\s]+?-----END CERTIFICATE-----/';

    public function __construct(
        private readonly Clock $clock,
        private readonly string $letsencryptDirectory,
        private readonly string $outputPath,
    ) {
    }

    /**
     * MAGUARI_LETSENCRYPT_DIR overrides /etc/letsencrypt for development and
     * tests only, like MAGUARI_CERTIFICATES_FILE for the output.
     */
    public static function fromEnvironment(): self
    {
        $directory = getenv('MAGUARI_LETSENCRYPT_DIR');

        return new self(
            new SystemClock(),
            is_string($directory) && $directory !== '' ? $directory : self::DEFAULT_LETSENCRYPT_DIRECTORY,
            CertificatesFile::pathFromEnvironment(),
        );
    }

    public function outputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Each readable certificate, in lineage name order, at most
     * MAX_CERTIFICATES. An instance without certbot has none.
     *
     * @return list<array{name: string, domains: list<string>, expires_at: int}>
     * @throws ClientFailure when the live directory exists but cannot be listed
     */
    public function scan(): array
    {
        $live = $this->letsencryptDirectory . '/live';

        if (!file_exists($live)) {
            return [];
        }

        $entries = @scandir($live);

        if ($entries === false) {
            throw new ClientFailure(sprintf('Cannot read %s. Run the certificate scanner as root.', $live));
        }

        $certificates = [];

        foreach ($entries as $name) {
            // ., .. and hidden entries; the README certbot puts there is a file.
            if (str_starts_with($name, '.') || !is_dir($live . '/' . $name)) {
                continue;
            }

            $certificate = self::read($name, $live . '/' . $name . '/cert.pem');

            if ($certificate === null) {
                continue;
            }

            $certificates[] = $certificate;

            if (count($certificates) === self::MAX_CERTIFICATES) {
                break;
            }
        }

        return $certificates;
    }

    /**
     * Replaces the output file atomically, with mode 0644: the dates and
     * domains are public (certificate transparency logs), and the client runs
     * as another user.
     *
     * @param list<array{name: string, domains: list<string>, expires_at: int}> $certificates
     * @throws ClientFailure
     */
    public function write(array $certificates): void
    {
        $directory = dirname($this->outputPath);

        if (!is_dir($directory)) {
            throw new ClientFailure(sprintf('Cannot write %s: the directory %s does not exist.', $this->outputPath, $directory));
        }

        $temporary = $directory . '/.' . basename($this->outputPath) . '.' . bin2hex(random_bytes(6));
        $json = json_encode(
            ['scanned_at' => $this->clock->now(), 'certificates' => $certificates],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) . "\n";

        try {
            $handle = @fopen($temporary, 'x');

            if ($handle === false) {
                throw new ClientFailure(sprintf('Cannot write %s.', $this->outputPath));
            }

            $written = fwrite($handle, $json);
            fflush($handle);
            fclose($handle);

            if ($written !== strlen($json) || !chmod($temporary, 0644) || !@rename($temporary, $this->outputPath)) {
                throw new ClientFailure(sprintf('Cannot write %s.', $this->outputPath));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return ?array{name: string, domains: list<string>, expires_at: int} null
     *         when the file is missing, too large or not a certificate
     */
    private static function read(string $name, string $path): ?array
    {
        $size = is_file($path) ? @filesize($path) : false;

        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES);

        // Only the first PEM block is parsed. openssl_x509_parse() would read
        // a string starting with file:// as a path.
        if (!is_string($contents) || preg_match(self::PEM_CERTIFICATE, $contents, $match) !== 1) {
            return null;
        }

        $parsed = @openssl_x509_parse($match[0]);
        $expiresAt = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if (!is_array($parsed) || !is_int($expiresAt)) {
            return null;
        }

        $domains = self::domains($parsed);

        return $domains === [] ? null : ['name' => $name, 'domains' => $domains, 'expires_at' => $expiresAt];
    }

    /**
     * The DNS names in subjectAltName, in their order, or the subject's
     * common name when there are none.
     *
     * @param array<mixed> $parsed from openssl_x509_parse()
     * @return list<string>
     */
    private static function domains(array $parsed): array
    {
        $alternativeNames = $parsed['extensions']['subjectAltName'] ?? null;
        $domains = [];

        if (is_string($alternativeNames)) {
            foreach (explode(',', $alternativeNames) as $entry) {
                $entry = trim($entry);

                if (str_starts_with($entry, 'DNS:')) {
                    $domains[] = substr($entry, 4);
                }
            }
        }

        $commonName = $parsed['subject']['CN'] ?? null;

        if ($domains === [] && is_string($commonName) && $commonName !== '') {
            $domains[] = $commonName;
        }

        return $domains;
    }
}
