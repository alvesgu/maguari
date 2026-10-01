<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

final class Settings
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {
    }

    public function get(string $name): ?string
    {
        $statement = $this->database->pdo()->prepare('SELECT value FROM access_settings WHERE name = ?');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $name, string $value): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO access_settings (name, value, updated_at) VALUES (?, ?, ?) '
                . 'ON CONFLICT (name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
        )->execute([$name, $value, $this->clock->now()]);
    }
}
