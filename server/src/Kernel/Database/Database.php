<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Database;

use PDO;
use PDOException;

/**
 * The SQLite database file. The connection opens on first use, so code that
 * never touches the database (for example check-seed-config) never needs it.
 */
final class Database
{
    public const DEFAULT_PATH = '/var/lib/maguari/maguari.sqlite';

    private ?PDO $pdo = null;

    private bool $inTransaction = false;

    public function __construct(
        private readonly string $path = self::DEFAULT_PATH,
    ) {
    }

    /**
     * MAGUARI_DATABASE overrides the path for development and tests only.
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('MAGUARI_DATABASE');

        return new self(is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Creates an empty database file with mode 0600 (and its directory with mode
     * 0700) if it does not exist yet. Only the CLI creates the database.
     *
     * @throws DatabaseUnavailable
     */
    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        $directory = dirname($this->path);

        // PHP's warnings are suppressed and their text goes into the exception
        // instead, so the CLI can print one clean line.
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DatabaseUnavailable($this->path, sprintf('could not create directory %s (%s)', $directory, self::lastError()));
        }

        $previousUmask = umask(0077);

        try {
            if (!@touch($this->path)) {
                throw new DatabaseUnavailable($this->path, sprintf('could not create the file (%s)', self::lastError()));
            }
        } finally {
            umask($previousUmask);
        }

        chmod($this->path, 0600);
    }

    /**
     * Opens the existing database. Never creates it: a missing file is an error.
     *
     * @throws DatabaseUnavailable
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!$this->exists()) {
            throw new DatabaseUnavailable($this->path, 'the file does not exist');
        }

        // The -wal and -shm files are created with the process umask.
        $previousUmask = umask(0077);

        try {
            $pdo = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $exception) {
            // For example an unreadable file, or one that is not SQLite.
            throw new DatabaseUnavailable($this->path, $exception->getMessage(), $exception);
        } finally {
            umask($previousUmask);
        }

        return $this->pdo = $pdo;
    }

    /**
     * Runs $callback inside BEGIN IMMEDIATE, so the write lock is taken up front
     * and two concurrent requests cannot both pass a check and then both write.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;

        try {
            $result = $callback($pdo);
            $pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $exception) {
            $pdo->exec('ROLLBACK');

            throw $exception;
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Whether transaction() is running. PDO::inTransaction() cannot tell,
     * because BEGIN IMMEDIATE is sent as plain SQL.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * The text of PHP's last warning without its "function(): " prefix, for
     * example "Permission denied".
     */
    private static function lastError(): string
    {
        $message = error_get_last()['message'] ?? 'unknown error';
        $position = strpos($message, '): ');

        return $position === false ? $message : substr($message, $position + 3);
    }
}
