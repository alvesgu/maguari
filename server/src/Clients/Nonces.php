<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Kernel\Database\Database;

/**
 * Nonces of accepted signed requests (clients_nonces). Call inside a
 * BEGIN IMMEDIATE transaction, so checking and recording a nonce cannot
 * interleave with another request.
 */
final class Nonces
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function seen(string $clientId, string $nonce): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM clients_nonces WHERE client_id = ? AND nonce = ?');
        $statement->execute([$clientId, $nonce]);

        return $statement->fetchColumn() !== false;
    }

    public function record(string $clientId, string $nonce, int $receivedAt, int $expiresAt): void
    {
        $this->database->pdo()->prepare('INSERT INTO clients_nonces (client_id, nonce, received_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$clientId, $nonce, $receivedAt, $expiresAt]);
    }

    /**
     * Accepted requests from the client received after $since.
     */
    public function countSince(string $clientId, int $since): int
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM clients_nonces WHERE client_id = ? AND received_at > ?');
        $statement->execute([$clientId, $since]);

        return (int) $statement->fetchColumn();
    }

    public function deleteExpired(int $now): void
    {
        $this->database->pdo()->prepare('DELETE FROM clients_nonces WHERE expires_at < ?')->execute([$now]);
    }
}
