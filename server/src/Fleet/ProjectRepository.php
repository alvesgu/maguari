<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Fleet\Exception\ProjectAlreadyAdded;
use Maguari\Server\Kernel\Database\Database;
use PDOException;

final class ProjectRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return Project[] in order of addition
     */
    public function all(): array
    {
        $rows = $this->database->pdo()->query('SELECT id, gcp_project_id, created_at FROM fleet_projects ORDER BY id')->fetchAll();

        return array_map(
            static fn (array $row): Project => new Project((int) $row['id'], $row['gcp_project_id'], (int) $row['created_at']),
            $rows,
        );
    }

    public function exists(string $gcpProjectId): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM fleet_projects WHERE gcp_project_id = ?');
        $statement->execute([$gcpProjectId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @throws ProjectAlreadyAdded when another request added it first
     */
    public function create(string $gcpProjectId, int $createdAt): Project
    {
        $pdo = $this->database->pdo();

        try {
            $pdo->prepare('INSERT INTO fleet_projects (gcp_project_id, created_at) VALUES (?, ?)')
                ->execute([$gcpProjectId, $createdAt]);
        } catch (PDOException $exception) {
            // SQLSTATE 23000: the UNIQUE constraint on gcp_project_id.
            if ($exception->getCode() === '23000') {
                throw new ProjectAlreadyAdded('This project is already added.');
            }

            throw $exception;
        }

        return new Project((int) $pdo->lastInsertId(), $gcpProjectId, $createdAt);
    }
}
