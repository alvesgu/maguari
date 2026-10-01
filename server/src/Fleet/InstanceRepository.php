<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Kernel\Database\Database;

final class InstanceRepository
{
    private const SELECT = 'SELECT i.id, i.project_id, p.gcp_project_id, i.gcp_instance_id, i.zone, i.name, i.picked_at '
        . 'FROM fleet_instances i JOIN fleet_projects p ON p.id = i.project_id';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * Stores the instance, or refreshes its GCP instance ID when it was picked
     * before. The first pick time is kept.
     */
    public function save(int $projectId, string $gcpInstanceId, string $zone, string $name, int $pickedAt): Instance
    {
        $this->database->pdo()->prepare(
            'INSERT INTO fleet_instances (project_id, gcp_instance_id, zone, name, picked_at) VALUES (?, ?, ?, ?, ?) '
                . 'ON CONFLICT (project_id, zone, name) DO UPDATE SET gcp_instance_id = excluded.gcp_instance_id',
        )->execute([$projectId, $gcpInstanceId, $zone, $name, $pickedAt]);

        $statement = $this->database->pdo()->prepare(self::SELECT . ' WHERE i.project_id = ? AND i.zone = ? AND i.name = ?');
        $statement->execute([$projectId, $zone, $name]);

        return self::instance($statement->fetch());
    }

    /**
     * @return Instance[] sorted by name, then zone
     */
    public function inProject(int $projectId): array
    {
        $statement = $this->database->pdo()->prepare(self::SELECT . ' WHERE i.project_id = ? ORDER BY i.name, i.zone');
        $statement->execute([$projectId]);

        return array_map(self::instance(...), $statement->fetchAll());
    }

    /**
     * @return Instance[] every picked instance, sorted by GCP project ID, name, then zone
     */
    public function all(): array
    {
        $statement = $this->database->pdo()->query(self::SELECT . ' ORDER BY p.gcp_project_id, i.name, i.zone');

        return array_map(self::instance(...), $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function instance(array $row): Instance
    {
        return new Instance(
            (int) $row['id'],
            (int) $row['project_id'],
            $row['gcp_project_id'],
            $row['gcp_instance_id'],
            $row['zone'],
            $row['name'],
            (int) $row['picked_at'],
        );
    }
}
