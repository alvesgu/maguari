<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\DatabaseCheck;

/**
 * How the client logs in to one database (design section 6.1.3). It lives
 * only in this instance's databases file and is never sent to the server.
 * Host, database and user are restricted to characters that cannot change a
 * PDO DSN; the password never goes into the DSN.
 */
final class DatabaseConnection
{
    private const HOST_PATTERN = '/^[A-Za-z0-9._:\/-]{1,253}$/D';
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_.@-]{1,63}$/D';
    private const MAX_PASSWORD_BYTES = 1024;

    /**
     * @param string $host a host name or address, or a Unix socket starting with /:
     *        its directory for PostgreSQL (/var/run/postgresql), the socket
     *        file itself for MySQL and MariaDB (/var/run/mysqld/mysqld.sock)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {
    }

    /**
     * Why these values cannot be used, for the set-database command; null when they can.
     */
    public static function problem(string $type, string $host, int $port, string $database, string $user, #[\SensitiveParameter] string $password): ?string
    {
        return match (true) {
            !isset(DatabaseCheck::DRIVERS[$type]) => 'The type is postgresql (also for PostGIS) or mysql (also for MariaDB).',
            preg_match(self::HOST_PATTERN, $host) !== 1 => 'The host is a name, an address or a Unix socket (its directory for PostgreSQL, such as /var/run/postgresql; the socket file for MySQL and MariaDB), without spaces.',
            $port < 1 || $port > 65535 => 'The port is a number from 1 to 65535.',
            preg_match(self::IDENTIFIER_PATTERN, $database) !== 1 => 'The database is its name on the server: letters, digits, _ . @ and -, at most 63.',
            preg_match(self::IDENTIFIER_PATTERN, $user) !== 1 => 'The user is a login name: letters, digits, _ . @ and -, at most 63.',
            strlen($password) > self::MAX_PASSWORD_BYTES || str_contains($password, "\0") || str_contains($password, "\n") => 'The password has at most 1,024 characters and no line breaks.',
            default => null,
        };
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        [$type, $host, $port, $database, $user, $password] = [$data['type'] ?? null, $data['host'] ?? null, $data['port'] ?? null, $data['database'] ?? null, $data['user'] ?? null, $data['password'] ?? null];

        if (!is_string($type) || !is_string($host) || !is_int($port) || !is_string($database) || !is_string($user) || !is_string($password)) {
            return null;
        }

        return self::problem($type, $host, $port, $database, $user, $password) === null ? new self($type, $host, $port, $database, $user, $password) : null;
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'user' => $this->user, 'password' => $this->password];
    }

    /**
     * For list-databases and messages: never the password.
     */
    public function describe(): string
    {
        $where = str_starts_with($this->host, '/') ? "socket in {$this->host}" : "{$this->host}:{$this->port}";

        return sprintf('%s, %s, database %s, user %s', $this->type, $where, $this->database, $this->user);
    }

    /**
     * The PDO DSN, without the user and password.
     */
    public function dsn(): string
    {
        return match ($this->type) {
            DatabaseCheck::POSTGRESQL => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;options=\'-c statement_timeout=%d\'',
                $this->host,
                $this->port,
                $this->database,
                DatabaseCheck::TIMEOUT_SECONDS,
                DatabaseCheck::TIMEOUT_SECONDS * 1000,
            ),
            default => str_starts_with($this->host, '/')
                ? sprintf('mysql:unix_socket=%s;dbname=%s', $this->host, $this->database)
                : sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->database),
        };
    }
}
