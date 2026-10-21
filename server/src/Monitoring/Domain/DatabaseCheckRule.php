<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Shared\DatabaseCheck;
use Maguari\Shared\Protocol;

/**
 * Turns a database result into Maguari's own sentence (design section
 * 6.1.3). Problems with the instance's own setup are "not checked", with what
 * to run there.
 */
final class DatabaseCheckRule
{
    /**
     * @param int $checkedAt the server's receive time of the heartbeat
     */
    public static function result(int $instanceId, ClientCheckResult $result, int $checkedAt): CheckResult
    {
        $name = $result->subject;
        $detail = match ($result->reason) {
            null => sprintf('SELECT 1 answered in %s ms.', number_format((int) $result->elapsedMs)),
            DatabaseCheck::REASON_UNREACHABLE => sprintf('Could not connect to the database server of %s, or it is not accepting connections.', $name),
            DatabaseCheck::REASON_TIMEOUT => sprintf('The database server of %s did not answer within %d seconds.', $name, DatabaseCheck::TIMEOUT_SECONDS),
            DatabaseCheck::REASON_LOGIN_REFUSED => sprintf('The database server refused the login of %s. Try it on the instance with: maguari-client check-database %s', $name, $name),
            DatabaseCheck::REASON_NO_DATABASE => sprintf('The database that %s names does not exist on its server, or its user may not use it.', $name),
            DatabaseCheck::REASON_QUERY_FAILED => sprintf('The login of %s worked, but SELECT 1 failed.', $name),
            DatabaseCheck::REASON_NOT_SET_UP => sprintf('No database named %s is set up on the instance. Run maguari-client set-database %s there.', $name, $name),
            DatabaseCheck::REASON_FILE_UNUSABLE => 'The client\'s database file cannot be used: it is unreadable, readable by others or not valid. Run maguari-client list-databases on the instance.',
            DatabaseCheck::REASON_DRIVER_MISSING => $result->state === DatabaseCheck::MYSQL
                ? 'The MySQL and MariaDB driver is not installed on the instance. Install it with: sudo apt install php-mysql'
                : 'The PostgreSQL driver is not installed on the instance. Install it with: sudo apt install php-pgsql',
            Protocol::CHECK_REASON_TIME_LIMIT => 'The client ran out of time for its checks before this one.',
            default => 'The check failed.',
        };

        return new CheckResult($instanceId, DatabaseCheck::TYPE, $result->outcome, $detail, $checkedAt, $name);
    }
}
