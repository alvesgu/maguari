<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CertificateHostname;

final class CertificateHostnameRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<CertificateHostname> in hostname order
     */
    public function forInstance(int $instanceId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, instance_id, hostname, added_at FROM monitoring_certificate_hostnames WHERE instance_id = ? ORDER BY hostname',
        );
        $statement->execute([$instanceId]);

        return array_map(
            static fn (array $row): CertificateHostname => new CertificateHostname(
                (int) $row['id'],
                (int) $row['instance_id'],
                $row['hostname'],
                (int) $row['added_at'],
            ),
            $statement->fetchAll(),
        );
    }

    /**
     * @return int the new row's ID
     */
    public function add(int $instanceId, string $hostname, int $at): int
    {
        $this->database->pdo()->prepare('INSERT INTO monitoring_certificate_hostnames (instance_id, hostname, added_at) VALUES (?, ?, ?)')
            ->execute([$instanceId, $hostname, $at]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @return bool whether the hostname existed for that instance
     */
    public function remove(int $instanceId, int $hostnameId): bool
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM monitoring_certificate_hostnames WHERE id = ? AND instance_id = ?');
        $statement->execute([$hostnameId, $instanceId]);

        return $statement->rowCount() === 1;
    }
}
