<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\DatabaseConnection;
use Maguari\Client\DatabaseConnector;

/**
 * Answers by database name on the server, without one: milliseconds, or a
 * reason. Anything not set up answers in 3 ms.
 */
final class FakeDatabaseConnector implements DatabaseConnector
{
    /** @var array<string, int|string> */
    private array $answers = [];

    /** @var list<DatabaseConnection> */
    public array $checked = [];

    public function answer(string $database, int|string $answer): void
    {
        $this->answers[$database] = $answer;
    }

    public function check(DatabaseConnection $connection): int|string
    {
        $this->checked[] = $connection;

        return $this->answers[$connection->database] ?? 3;
    }
}
