<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

final class DailyJobAlreadyRunning extends \RuntimeException
{
    public function __construct(int $startedAt)
    {
        parent::__construct('The daily job is already running, started at ' . gmdate('Y-m-d H:i', $startedAt) . ' UTC.');
    }
}
