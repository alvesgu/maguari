<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Secrets;

/**
 * The key that encrypts secrets at rest (design section 9.3): 32 raw bytes in
 * a file readable only by the app's user.
 */
final class SecretKeyFile
{
    public const DEFAULT_PATH = '/etc/maguari/secret.key';

    public function __construct(
        private readonly string $path = self::DEFAULT_PATH,
    ) {
    }

    /**
     * MAGUARI_SECRET_KEY_FILE overrides the path for development and tests only.
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('MAGUARI_SECRET_KEY_FILE');

        return new self(is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Creates the file with a new random key and mode 0600 (and its directory
     * with mode 0700) unless it exists. An existing file is never overwritten:
     * a new key would make every stored secret unreadable.
     *
     * @return bool false when the file already existed
     * @throws SecretKeyUnavailable
     */
    public function create(): bool
    {
        if (file_exists($this->path) || is_link($this->path)) {
            return false;
        }

        $directory = dirname($this->path);
        // Nothing created here is ever readable by others, not even briefly.
        $previousUmask = umask(0077);

        try {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new SecretKeyUnavailable(sprintf('Cannot create the secret key file %s: could not create its directory.', $this->path));
            }

            // Mode x fails if the file appeared in the meantime, so a key is
            // never overwritten.
            $handle = @fopen($this->path, 'x');

            if ($handle === false) {
                throw new SecretKeyUnavailable(sprintf('Cannot create the secret key file %s.', $this->path));
            }

            $written = fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            fclose($handle);

            if ($written !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                @unlink($this->path);

                throw new SecretKeyUnavailable(sprintf('Cannot write the secret key file %s.', $this->path));
            }

            chmod($this->path, 0600);
        } finally {
            umask($previousUmask);
        }

        return true;
    }

    /**
     * @throws SecretKeyUnavailable when the file is missing, unreadable, readable
     *                              by group or others, or not exactly 32 bytes
     */
    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new SecretKeyUnavailable(sprintf('The secret key file %s does not exist.', $this->path));
        }

        $permissions = @fileperms($this->path);

        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new SecretKeyUnavailable(sprintf('The secret key file %s must be readable only by its owner (mode 0600).', $this->path));
        }

        $key = @file_get_contents($this->path);

        if ($key === false) {
            throw new SecretKeyUnavailable(sprintf('The secret key file %s cannot be read.', $this->path));
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SecretKeyUnavailable(sprintf('The secret key file %s is not a valid key.', $this->path));
        }

        return $key;
    }
}
