<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Database\Database;

final class SmtpSettingsRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return array{host: string, port: int, username: string, password_ciphertext: ?string, from_address: string, updated_at: int}|null
     */
    public function find(): ?array
    {
        $row = $this->database->pdo()
            ->query('SELECT host, port, username, password_ciphertext, from_address, updated_at FROM notifications_smtp_settings WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'host' => (string) $row['host'],
            'port' => (int) $row['port'],
            'username' => (string) $row['username'],
            'password_ciphertext' => $row['password_ciphertext'] === null ? null : (string) $row['password_ciphertext'],
            'from_address' => (string) $row['from_address'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    public function save(string $host, int $port, string $username, ?string $passwordCiphertext, string $fromAddress, int $updatedAt): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO notifications_smtp_settings (id, host, port, username, password_ciphertext, from_address, updated_at) '
                . 'VALUES (1, ?, ?, ?, ?, ?, ?) '
                . 'ON CONFLICT (id) DO UPDATE SET host = excluded.host, port = excluded.port, username = excluded.username, '
                . 'password_ciphertext = excluded.password_ciphertext, from_address = excluded.from_address, '
                . 'updated_at = excluded.updated_at',
        );
        $statement->bindValue(1, $host);
        $statement->bindValue(2, $port, \PDO::PARAM_INT);
        $statement->bindValue(3, $username);
        $statement->bindValue(4, $passwordCiphertext, $passwordCiphertext === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $statement->bindValue(5, $fromAddress);
        $statement->bindValue(6, $updatedAt, \PDO::PARAM_INT);
        $statement->execute();
    }
}
