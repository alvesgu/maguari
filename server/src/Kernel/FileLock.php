<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel;

/**
 * An exclusive, non-blocking flock() on a file. The lock goes away with the
 * process, so a killed process never leaves it held.
 */
final class FileLock
{
    /**
     * @param resource $handle
     */
    private function __construct(
        private $handle,
    ) {
    }

    /**
     * Creates the file if needed, readable only by this user.
     *
     * @return ?self null while another process holds the lock
     * @throws \RuntimeException when the file cannot be opened
     */
    public static function tryAcquire(string $path): ?self
    {
        $previousUmask = umask(0077);

        try {
            $handle = @fopen($path, 'c');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open the lock file %s.', $path));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
