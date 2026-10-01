<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Kernel\Database\Database;

final class ClientRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * Stores a new client for the instance, removing the instance's previous
     * client, whose credentials stop working. Call inside a transaction.
     */
    public function replace(
        string $clientId,
        int $instanceId,
        string $secretCiphertext,
        int $enrolledAt,
        string $clientVersion,
        int $protocolVersion,
    ): void {
        $pdo = $this->database->pdo();
        $pdo->prepare('DELETE FROM clients_clients WHERE instance_id = ?')->execute([$instanceId]);
        $statement = $pdo->prepare(
            'INSERT INTO clients_clients (client_id, instance_id, secret_ciphertext, enrolled_at, client_version, protocol_version) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        $statement->bindValue(1, $clientId);
        $statement->bindValue(2, $instanceId, \PDO::PARAM_INT);
        $statement->bindValue(3, $secretCiphertext, \PDO::PARAM_LOB);
        $statement->bindValue(4, $enrolledAt, \PDO::PARAM_INT);
        $statement->bindValue(5, $clientVersion);
        $statement->bindValue(6, $protocolVersion, \PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * @param int[] $instanceIds
     * @return int[] those of $instanceIds that have a client
     */
    public function enrolledAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));
        $statement = $this->database->pdo()->prepare("SELECT instance_id FROM clients_clients WHERE instance_id IN ({$placeholders})");
        $statement->execute(array_values($instanceIds));

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
