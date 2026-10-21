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

    public function secretCiphertext(string $clientId): ?string
    {
        $statement = $this->database->pdo()->prepare('SELECT secret_ciphertext FROM clients_clients WHERE client_id = ?');
        $statement->execute([$clientId]);
        $ciphertext = $statement->fetchColumn();

        return $ciphertext === false ? null : (string) $ciphertext;
    }

    public function instanceId(string $clientId): ?int
    {
        $statement = $this->database->pdo()->prepare('SELECT instance_id FROM clients_clients WHERE client_id = ?');
        $statement->execute([$clientId]);
        $instanceId = $statement->fetchColumn();

        return $instanceId === false ? null : (int) $instanceId;
    }

    public function recordHeartbeat(string $clientId, int $receivedAt, string $clientVersion, int $protocolVersion, ReportedCheckSettings $settings): void
    {
        $this->database->pdo()->prepare(
            'UPDATE clients_clients SET last_heartbeat_at = ?, client_version = ?, protocol_version = ?, check_settings_supported = ?, check_settings_version = ? WHERE client_id = ?',
        )->execute([$receivedAt, $clientVersion, $protocolVersion, $settings->supported ? 1 : 0, $settings->version, $clientId]);
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, ReportedCheckSettings> by instance ID, for those of
     *         $instanceIds whose client has sent a heartbeat
     */
    public function reportedCheckSettingsAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT instance_id, check_settings_supported, check_settings_version FROM clients_clients WHERE last_heartbeat_at IS NOT NULL AND instance_id IN ({$placeholders})",
        );
        $statement->execute(array_values($instanceIds));
        $reported = [];

        foreach ($statement->fetchAll() as $row) {
            $reported[(int) $row['instance_id']] = new ReportedCheckSettings((int) $row['check_settings_supported'] === 1, $row['check_settings_version']);
        }

        return $reported;
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, string> client version by instance ID, for those of
     *         $instanceIds that have a client
     */
    public function clientVersionsAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT instance_id, client_version FROM clients_clients WHERE instance_id IN ({$placeholders})",
        );
        $statement->execute(array_values($instanceIds));
        $versions = [];

        foreach ($statement->fetchAll() as $row) {
            $versions[(int) $row['instance_id']] = (string) $row['client_version'];
        }

        return $versions;
    }
}
