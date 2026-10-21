<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\DatabaseCheck;

/**
 * The databases the client may check and how to log in to each (design
 * section 6.1.3): `databases.json` next to the credentials, written and read
 * with the same rules (PrivateFile), managed by the set-database,
 * remove-database and list-databases commands. The passwords are plain text:
 * a key to encrypt them would have to sit next to the file.
 */
final class DatabasesFile
{
    private const FILE_NAME = 'databases.json';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function path(): string
    {
        return $this->directory . '/' . self::FILE_NAME;
    }

    /**
     * @return array<string, DatabaseConnection> by name, in name order; empty when the file is missing
     * @throws ClientFailure when it is readable by others or not valid
     */
    public function load(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        if (!PrivateFile::isPrivate($path)) {
            throw new ClientFailure(sprintf('The databases file %s must be readable only by its owner. Fix it with: chmod 600 %s', $path, escapeshellarg($path)));
        }

        $data = json_decode((string) @file_get_contents($path), true);
        $connections = [];

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ClientFailure(sprintf('The databases file %s is not valid. Fix or remove it, then set up the databases again with maguari-client set-database.', $path));
        }

        foreach ($data as $name => $connection) {
            $connection = is_string($name) && DatabaseCheck::isValidName($name) ? DatabaseConnection::fromArray($connection) : null;

            if ($connection === null) {
                throw new ClientFailure(sprintf('The databases file %s has an entry that is not valid: %s. Fix or remove it.', $path, (string) $name));
            }

            $connections[$name] = $connection;
        }

        ksort($connections);

        return $connections;
    }

    /**
     * @param array<string, DatabaseConnection> $connections by name
     * @throws ClientFailure
     */
    public function save(array $connections): void
    {
        ksort($connections);
        PrivateFile::write(
            $this->path(),
            json_encode(array_map(static fn (DatabaseConnection $connection): array => $connection->toArray(), $connections), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_FORCE_OBJECT) . "\n",
            'databases file',
        );
    }
}
