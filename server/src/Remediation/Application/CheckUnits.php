<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Application;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Infrastructure\CheckUnitRepository;
use Maguari\Shared\ServiceCheck;

/**
 * The unit an administrator associates with a local HTTP or database check,
 * so the ladder may restart it (design section 7.1).
 */
final class CheckUnits
{
    public function __construct(
        private readonly Database $database,
        private readonly CheckUnitRepository $units,
        private readonly MonitoringApi $monitoring,
    ) {
    }

    /**
     * @throws \Maguari\Server\Monitoring\Exception\InvalidClientCheck with a
     *         sentence for the administrator
     */
    public function set(int $instanceId, int $checkId, string $input): void
    {
        $unit = trim($input) === '' ? null : MonitoringApi::normalizeServiceUnit($input);

        foreach ($this->monitoring->clientCheckStatuses([$instanceId])[$instanceId] ?? [] as $status) {
            $check = $status->check;

            // A service check restarts its own unit.
            if ($check->id !== $checkId || $check->type === ServiceCheck::TYPE) {
                continue;
            }

            $this->database->transaction(function () use ($instanceId, $check, $unit): void {
                if ($unit === null) {
                    $this->units->delete($instanceId, $check->type, $check->subject());
                } else {
                    $this->units->save($instanceId, $check->type, $check->subject(), $unit);
                }
            });
        }
    }
}
