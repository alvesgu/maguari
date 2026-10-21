<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The database check's rules and fields (design section 6.1.3). The server
 * only names a database; how to log in to it stays in a file on the instance,
 * never sent to or stored on the server.
 */
final class DatabaseCheck
{
    public const TYPE = 'database';

    /** The kinds of database server a connection may be, with the PDO driver and Ubuntu package each needs. */
    public const POSTGRESQL = 'postgresql';
    public const MYSQL = 'mysql';
    public const DRIVERS = [self::POSTGRESQL => 'pdo_pgsql', self::MYSQL => 'pdo_mysql'];
    public const PACKAGES = [self::POSTGRESQL => 'php-pgsql', self::MYSQL => 'php-mysql'];
    public const DEFAULT_PORTS = [self::POSTGRESQL => 5432, self::MYSQL => 3306];

    // Why a check failed (design section 5.2).
    public const REASON_UNREACHABLE = 'unreachable';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_LOGIN_REFUSED = 'login_refused';
    public const REASON_NO_DATABASE = 'no_database';
    public const REASON_QUERY_FAILED = 'query_failed';
    public const FAIL_REASONS = [self::REASON_UNREACHABLE, self::REASON_TIMEOUT, self::REASON_LOGIN_REFUSED, self::REASON_NO_DATABASE, self::REASON_QUERY_FAILED];

    // Why it could not run: a problem with the instance's own setup.
    public const REASON_NOT_SET_UP = 'not_set_up';
    public const REASON_FILE_UNUSABLE = 'file_unusable';
    public const REASON_DRIVER_MISSING = 'driver_missing';

    /** Connecting, and then the query. */
    public const TIMEOUT_SECONDS = 5;

    /**
     * A database's name in the web app and in the instance's file: 1 to 63
     * bytes of lowercase letters, digits, "_" and "-", starting with a letter
     * or digit. Only a key into the file.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $name) === 1;
    }
}
