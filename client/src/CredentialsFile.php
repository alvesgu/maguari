<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\ServerUrl;

/**
 * The credentials file, readable only by the client's own user (mode 0600, in
 * a directory with mode 0700 when the client creates it). It holds the HMAC
 * secret, so it is never readable by anyone else, not even while it is being
 * written.
 */
final class CredentialsFile
{
    public const DEFAULT_DIRECTORY = '/var/lib/maguari-client';
    private const FILE_NAME = 'credentials.json';
    private const CLIENT_ID_PATTERN = '/^[0-9a-f]{32}$/D';

    public function __construct(
        private readonly string $directory = self::DEFAULT_DIRECTORY,
    ) {
    }

    /**
     * MAGUARI_CLIENT_DIR overrides the directory for development and tests only.
     */
    public static function fromEnvironment(): self
    {
        $directory = getenv('MAGUARI_CLIENT_DIR');

        return new self(is_string($directory) && $directory !== '' ? $directory : self::DEFAULT_DIRECTORY);
    }

    public function path(): string
    {
        return $this->directory . '/' . self::FILE_NAME;
    }

    /**
     * Replaces the file atomically: a temporary file is created with mode 0600
     * before anything is written to it, then renamed into place. If anything
     * fails, the existing file is left untouched.
     *
     * @throws ClientFailure
     */
    public function save(Credentials $credentials): void
    {
        $previousUmask = umask(0077);
        $temporary = $this->directory . '/.' . self::FILE_NAME . '.' . bin2hex(random_bytes(6));

        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                throw new ClientFailure(sprintf('Cannot create the directory %s for the credentials file.', $this->directory));
            }

            $handle = @fopen($temporary, 'x');

            if ($handle === false) {
                throw new ClientFailure(sprintf('Cannot write the credentials file in %s.', $this->directory));
            }

            chmod($temporary, 0600);
            $json = json_encode([
                'server_url' => $credentials->serverUrl,
                'client_id' => $credentials->clientId,
                'secret' => rtrim(strtr(base64_encode($credentials->secret), '+/', '-_'), '='),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            $written = fwrite($handle, $json);
            fflush($handle);
            fclose($handle);

            if ($written !== strlen($json) || !@rename($temporary, $this->path())) {
                throw new ClientFailure(sprintf('Cannot write the credentials file %s.', $this->path()));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            umask($previousUmask);
        }
    }

    /**
     * @throws ClientFailure when the file is missing, readable or writable by
     *                       group or others, or not valid
     */
    public function load(): Credentials
    {
        $path = $this->path();

        if (!is_file($path)) {
            throw new ClientFailure(sprintf('This client is not enrolled: %s does not exist. Run maguari-client enroll first.', $path));
        }

        $permissions = @fileperms($path);

        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new ClientFailure(sprintf(
                'The credentials file %s must be readable only by its owner. Fix it with: chmod 600 %s',
                $path,
                escapeshellarg($path),
            ));
        }

        $data = json_decode((string) @file_get_contents($path), true);
        $serverUrl = is_array($data) && is_string($data['server_url'] ?? null) ? ServerUrl::normalize($data['server_url']) : null;
        $clientId = is_array($data) ? ($data['client_id'] ?? null) : null;
        $secret = is_array($data) && is_string($data['secret'] ?? null) ? self::decodeSecret($data['secret']) : null;

        if ($serverUrl === null || !is_string($clientId) || preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1 || $secret === null) {
            throw new ClientFailure(sprintf('The credentials file %s is not valid. Enroll the client again.', $path));
        }

        return new Credentials($serverUrl, $clientId, $secret);
    }

    /**
     * @return string|null the 32-byte secret, or null when $encoded is not one
     */
    public static function decodeSecret(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $encoded) !== 1) {
            return null;
        }

        $secret = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($secret) && strlen($secret) === 32 ? $secret : null;
    }
}
