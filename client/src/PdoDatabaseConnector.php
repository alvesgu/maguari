<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\DatabaseCheck;

/**
 * DatabaseConnector on PDO. The driver for each type (pdo_pgsql, pdo_mysql)
 * is optional: only an instance with a database check of that type needs it,
 * and without it the check is "not checked" with the package to install.
 * Connecting takes at most DatabaseCheck::TIMEOUT_SECONDS, and so does the
 * query.
 */
final class PdoDatabaseConnector implements DatabaseConnector
{
    /**
     * @param ?\Closure(string): bool $driverLoaded whether a PDO driver is there; extension_loaded() by default
     */
    public function __construct(
        private readonly ?\Closure $driverLoaded = null,
    ) {
    }

    public function hasDriver(string $type): bool
    {
        $driver = DatabaseCheck::DRIVERS[$type] ?? '';

        return $this->driverLoaded === null ? extension_loaded($driver) : ($this->driverLoaded)($driver);
    }

    public function check(DatabaseConnection $connection): int|string
    {
        if (!$this->hasDriver($connection->type)) {
            return DatabaseCheck::REASON_DRIVER_MISSING;
        }

        $start = microtime(true);

        try {
            $pdo = new \PDO($connection->dsn(), $connection->user, $connection->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => DatabaseCheck::TIMEOUT_SECONDS,
            ]);
        } catch (\PDOException $exception) {
            return self::connectFailure($exception->getMessage(), $exception->getCode());
        }

        try {
            // The hint bounds the query on MySQL; MariaDB ignores it, and PostgreSQL has statement_timeout in the DSN.
            $pdo->query($connection->type === DatabaseCheck::MYSQL ? 'SELECT /*+ MAX_EXECUTION_TIME(5000) */ 1' : 'SELECT 1')->fetchColumn();
        } catch (\PDOException $exception) {
            return stripos($exception->getMessage(), 'timeout') !== false || stripos($exception->getMessage(), 'canceling statement') !== false
                ? DatabaseCheck::REASON_TIMEOUT
                : DatabaseCheck::REASON_QUERY_FAILED;
        } finally {
            $pdo = null;
        }

        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Why the connection failed, from the driver's message and code: PDO
     * gives PostgreSQL's reasons only in the message (the code is 08006 for
     * all of them), and MySQL's as client and server error numbers.
     */
    public static function connectFailure(string $message, int|string $code): string
    {
        $mysqlError = is_int($code) ? $code : (preg_match('/SQLSTATE\[[0-9A-Z]+\] \[(\d+)\]/', $message, $match) === 1 ? (int) $match[1] : 0);

        return match (true) {
            // MySQL and MariaDB answer 1044 alike for a database that is missing and for one the user may not use.
            preg_match('/database "[^"]*" does not exist/i', $message) === 1,
            in_array($mysqlError, [1044, 1049], true) => DatabaseCheck::REASON_NO_DATABASE,
            stripos($message, 'password authentication failed') !== false,
            stripos($message, 'no pg_hba.conf entry') !== false,
            stripos($message, 'peer authentication failed') !== false,
            preg_match('/role "[^"]*" does not exist/i', $message) === 1,
            in_array($mysqlError, [1045, 1698], true),
            stripos($message, 'Access denied') !== false => DatabaseCheck::REASON_LOGIN_REFUSED,
            stripos($message, 'timeout expired') !== false,
            stripos($message, 'timed out') !== false => DatabaseCheck::REASON_TIMEOUT,
            default => DatabaseCheck::REASON_UNREACHABLE,
        };
    }
}
