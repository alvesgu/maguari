<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Remediation\Domain\Action;
use Maguari\Server\Remediation\Domain\ActionKind;
use Maguari\Server\Remediation\Domain\ActionOutcome;
use Maguari\Server\Remediation\Domain\InstanceRemediation;
use Maguari\Server\Remediation\Domain\Mode;

final class InstanceRemediationRepository
{
    private const ACTION_COLUMNS = 'a.id, a.instance_id, a.action, a.unit, a.dry_run, a.decided_at, a.finished_at, a.outcome, '
        . '(SELECT GROUP_CONCAT(l.incident_id) FROM remediation_action_incidents AS l WHERE l.action_id = a.id) AS incident_ids';

    /** What the rules look back on: the 24 hours of the longest limit. */
    private const ACTIONS_FOR_SECONDS = 86_400;

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * The aggregate, with the actions of the last 24 hours and those serving
     * open incidents, which may be older.
     */
    public function find(int $instanceId, int $at): InstanceRemediation
    {
        $statement = $this->database->pdo()->prepare('SELECT mode, mode_changed_at, maintenance_until FROM remediation_instances WHERE instance_id = ?');
        $statement->execute([$instanceId]);
        $row = $statement->fetch();
        $actions = $this->actions(
            'WHERE a.instance_id = ? AND (a.decided_at >= ? OR a.id IN ('
                . 'SELECT l.action_id FROM remediation_action_incidents AS l JOIN remediation_incidents AS i ON i.id = l.incident_id WHERE i.resolved_at IS NULL'
                . ')) ORDER BY a.id',
            [$instanceId, $at - self::ACTIONS_FOR_SECONDS],
        );

        return $row === false
            ? new InstanceRemediation($instanceId, Mode::AlertOnly, 0, null, $actions)
            : new InstanceRemediation(
                $instanceId,
                Mode::from($row['mode']),
                (int) $row['mode_changed_at'],
                $row['maintenance_until'] === null ? null : (int) $row['maintenance_until'],
                $actions,
            );
    }

    /**
     * The mode and maintenance only. Call inside a transaction.
     */
    public function save(InstanceRemediation $instance): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO remediation_instances (instance_id, mode, mode_changed_at, maintenance_until) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT (instance_id) DO UPDATE SET mode = excluded.mode, mode_changed_at = excluded.mode_changed_at, maintenance_until = excluded.maintenance_until',
        )->execute([$instance->instanceId, $instance->mode->value, $instance->modeChangedAt, $instance->maintenanceUntil]);
    }

    /**
     * Inserts a new action or adds the incidents a stored one now serves.
     * Call inside a transaction.
     *
     * @return Action as stored, with its ID
     */
    public function saveAction(Action $action): Action
    {
        $pdo = $this->database->pdo();

        if ($action->id === null) {
            $pdo->prepare(
                'INSERT INTO remediation_actions (instance_id, action, unit, dry_run, decided_at, finished_at, outcome) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$action->instanceId, $action->kind->value, $action->unit, (int) $action->dryRun, $action->decidedAt, $action->finishedAt, $action->outcome?->value]);
            $action = $action->withId((int) $pdo->lastInsertId());
        }

        $link = $pdo->prepare('INSERT OR IGNORE INTO remediation_action_incidents (action_id, incident_id) VALUES (?, ?)');

        foreach ($action->incidentIds as $incidentId) {
            $link->execute([$action->id, $incidentId]);
        }

        return $action;
    }

    public function inMaintenance(int $instanceId, int $at): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM remediation_instances WHERE instance_id = ? AND maintenance_until > ?');
        $statement->execute([$instanceId, $at]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<int> the automatic instances with open incidents, in
     *         maintenance or not: InstanceRemediation decides that
     */
    public function automaticWithOpenIncidents(): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT r.instance_id FROM remediation_instances AS r WHERE r.mode = ? AND EXISTS ('
                . 'SELECT 1 FROM remediation_incidents AS i WHERE i.instance_id = r.instance_id AND i.resolved_at IS NULL'
                . ') ORDER BY r.instance_id',
        );
        $statement->execute([Mode::Automatic->value]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<Action> newest first
     */
    public function actionsOf(int $instanceId, int $limit): array
    {
        return $this->actions('WHERE a.instance_id = ? ORDER BY a.id DESC LIMIT ?', [$instanceId, $limit]);
    }

    /**
     * @param ?int $before only actions with a lower ID
     * @return list<Action> newest first
     */
    public function recentActions(int $limit, ?int $before): array
    {
        return $before === null
            ? $this->actions('ORDER BY a.id DESC LIMIT ?', [$limit])
            : $this->actions('WHERE a.id < ? ORDER BY a.id DESC LIMIT ?', [$before, $limit]);
    }

    /**
     * @param list<int> $parameters
     * @return list<Action>
     */
    private function actions(string $where, array $parameters): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::ACTION_COLUMNS . ' FROM remediation_actions AS a ' . $where);

        foreach ($parameters as $i => $value) {
            $statement->bindValue($i + 1, $value, \PDO::PARAM_INT);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Action => new Action(
                (int) $row['id'],
                (int) $row['instance_id'],
                ActionKind::from($row['action']),
                $row['unit'],
                (bool) $row['dry_run'],
                (int) $row['decided_at'],
                $row['finished_at'] === null ? null : (int) $row['finished_at'],
                $row['outcome'] === null ? null : ActionOutcome::from($row['outcome']),
                $row['incident_ids'] === null ? [] : array_map('intval', explode(',', $row['incident_ids'])),
            ),
            $statement->fetchAll(),
        );
    }
}
