<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\Resolution;

final class IncidentRepository
{
    private const COLUMNS = 'id, instance_id, check_name, subject, opened_at, passing_since, resolved_at, resolution';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function openFor(IncidentKey $key): ?Incident
    {
        $incidents = $this->select(
            'WHERE resolved_at IS NULL AND instance_id = ? AND check_name = ? AND subject = ?',
            [$key->instanceId, $key->checkName, $key->subject],
        );

        return $incidents[0] ?? null;
    }

    /**
     * @return list<Incident> every open incident, oldest first
     */
    public function allOpen(): array
    {
        return $this->select('WHERE resolved_at IS NULL ORDER BY id');
    }

    /**
     * @param int[] $instanceIds
     * @return list<Incident> newest first
     */
    public function openAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));

        return $this->select("WHERE resolved_at IS NULL AND instance_id IN ({$placeholders}) ORDER BY id DESC", array_values($instanceIds));
    }

    /**
     * @return list<Incident> open incidents passing since at or before $passingSince, oldest first
     */
    public function openPassingSince(int $passingSince): array
    {
        return $this->select('WHERE resolved_at IS NULL AND passing_since <= ? ORDER BY id', [$passingSince]);
    }

    /**
     * @return list<Incident> newest resolved first
     */
    public function resolvedFor(int $instanceId, int $limit): array
    {
        return $this->select('WHERE instance_id = ? AND resolved_at IS NOT NULL ORDER BY resolved_at DESC, id DESC LIMIT ?', [$instanceId, $limit]);
    }

    /**
     * Inserts a new incident or updates a stored one. Call inside a transaction.
     *
     * @return Incident the incident as stored, with its ID
     */
    public function save(Incident $incident): Incident
    {
        $pdo = $this->database->pdo();
        $values = [$incident->passingSince, $incident->resolvedAt, $incident->resolution?->value];

        if ($incident->id === null) {
            $pdo->prepare(
                'INSERT INTO remediation_incidents (instance_id, check_name, subject, opened_at, passing_since, resolved_at, resolution) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$incident->key->instanceId, $incident->key->checkName, $incident->key->subject, $incident->openedAt, ...$values]);

            return $incident->withId((int) $pdo->lastInsertId());
        }

        $pdo->prepare('UPDATE remediation_incidents SET passing_since = ?, resolved_at = ?, resolution = ? WHERE id = ?')
            ->execute([...$values, $incident->id]);

        return $incident;
    }

    public function lastReconciledAt(): ?int
    {
        $ranAt = $this->database->pdo()->query('SELECT MAX(ran_at) FROM remediation_reconciliations')->fetchColumn();

        return $ranAt === null || $ranAt === false ? null : (int) $ranAt;
    }

    public function recordReconciliation(int $at, int $opened, int $resolved): void
    {
        $this->database->pdo()->prepare('INSERT INTO remediation_reconciliations (ran_at, opened, resolved) VALUES (?, ?, ?)')
            ->execute([$at, $opened, $resolved]);
    }

    /**
     * @param list<int|string> $parameters
     * @return list<Incident>
     */
    private function select(string $where, array $parameters = []): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM remediation_incidents ' . $where);

        foreach (array_values($parameters) as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Incident => new Incident(
                (int) $row['id'],
                new IncidentKey((int) $row['instance_id'], $row['check_name'], $row['subject']),
                (int) $row['opened_at'],
                $row['passing_since'] === null ? null : (int) $row['passing_since'],
                $row['resolved_at'] === null ? null : (int) $row['resolved_at'],
                $row['resolution'] === null ? null : Resolution::from($row['resolution']),
            ),
            $statement->fetchAll(),
        );
    }
}
