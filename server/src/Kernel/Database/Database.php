<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Database;

use PDO;
use RuntimeException;

/**
 * The SQLite database file. The connection opens on first use, so code that
 * never touches the database (for example check-seed-config) never needs it.
 */
final class Database
{
    public const DEFAULT_PATH = '/var/lib/maguari/maguari.sqlite';

    private ?PDO $pdo = null;

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
     */
    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
        }

        $previousUmask = umask(0077);

        try {
            if (!touch($this->path)) {
                throw new RuntimeException(sprintf('Could not create database "%s".', $this->path));
            }
        } finally {
            umask($previousUmask);
        }

        chmod($this->path, 0600);
    }

    /**
     * Opens the existing database. Never creates it: a missing file is an error.
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!$this->exists()) {
            throw new RuntimeException(sprintf('Database "%s" does not exist.', $this->path));
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

        try {
            $result = $callback($pdo);
            $pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $exception) {
            $pdo->exec('ROLLBACK');

            throw $exception;
        }
    }
}
