<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Logs in to a database and runs SELECT 1 (design section 6.1.3).
 */
interface DatabaseConnector
{
    /**
     * @return int|string the milliseconds SELECT 1 took from the start of
     *         the connection, or one of DatabaseCheck's reasons
     */
    public function check(DatabaseConnection $connection): int|string;
}
