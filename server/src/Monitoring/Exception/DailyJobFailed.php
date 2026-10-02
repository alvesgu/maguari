<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * The daily job hit an error and its run was marked failed. The message names
 * the error, for the log; exception messages never contain secrets.
 */
final class DailyJobFailed extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        $message = sprintf('The daily job failed: %s: %s', $previous::class, $previous->getMessage());

        parent::__construct(str_replace(["\r", "\n"], ' ', $message), 0, $previous);
    }
}
