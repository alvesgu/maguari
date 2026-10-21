<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Files in the client's directory that only its own user may read (mode
 * 0600, in a directory with mode 0700 when the client creates it), written
 * so they are never readable by anyone else, not even while being written.
 */
final class PrivateFile
{
    /**
     * Replaces the file atomically: a temporary file is created with mode 0600
     * before anything is written to it, then renamed into place. If anything
     * fails, the existing file is left untouched.
     *
     * @param string $what the file's name for people, for example "credentials file"
     * @throws ClientFailure
     */
    public static function write(string $path, string $contents, string $what): void
    {
        $directory = dirname($path);
        $previousUmask = umask(0077);
        $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(6));

        try {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new ClientFailure(sprintf('Cannot create the directory %s for the %s.', $directory, $what));
            }

            $handle = @fopen($temporary, 'x');

            if ($handle === false) {
                throw new ClientFailure(sprintf('Cannot write the %s in %s.', $what, $directory));
            }

            chmod($temporary, 0600);
            $written = fwrite($handle, $contents);
            fflush($handle);
            fclose($handle);

            if ($written !== strlen($contents) || !@rename($temporary, $path)) {
                throw new ClientFailure(sprintf('Cannot write the %s %s.', $what, $path));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            umask($previousUmask);
        }
    }

    /**
     * Whether only the owner can access the file.
     */
    public static function isPrivate(string $path): bool
    {
        // A mode changed by someone else since PHP last looked must count.
        clearstatcache(true, $path);
        $permissions = @fileperms($path);

        return $permissions !== false && ($permissions & 0077) === 0;
    }
}
