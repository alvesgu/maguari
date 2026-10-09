<?php

declare(strict_types=1);

namespace Maguari\Server\Cli;

use Maguari\Server\Kernel\Events\DeliveryReport;
use Maguari\Server\Kernel\Events\EventDelivery;

/**
 * What `maguari-server tick` prints (design section 12.2.1). Nothing when
 * nothing happened, so the journal stays quiet. Pure, so it is tested without
 * running the tick.
 */
final class TickReport
{
    /**
     * @return list<string> for stdout, without line endings
     */
    public static function lines(DeliveryReport $report): array
    {
        $lines = [];

        foreach ($report->delivered as $event) {
            $lines[] = "Delivered event {$event->id} ({$event->type}).";
        }

        if ($report->limitReached) {
            $lines[] = sprintf(
                'Stopped after %d events; the rest are delivered on the next tick.',
                count($report->delivered) + count($report->failures),
            );
        }

        return $lines;
    }

    /**
     * @return list<string> for stderr, one per failed event, without line endings
     */
    public static function errorLines(DeliveryReport $report): array
    {
        $lines = [];

        foreach ($report->failures as $failure) {
            $exception = $failure->exception;
            $line = sprintf(
                'The event %d (%s) failed: %s: %s',
                $failure->id,
                $failure->type,
                $exception::class,
                str_replace(["\r", "\n"], ' ', $exception->getMessage()),
            );
            $line .= $failure->skipped
                ? sprintf(' It failed on %d ticks and is now skipped.', $failure->failedAttempts)
                : sprintf(
                    ' Delivery stops here until the next tick (failed %d of %d times before it is skipped).',
                    $failure->failedAttempts,
                    EventDelivery::MAX_FAILED_ATTEMPTS,
                );
            $lines[] = $line;
        }

        return $lines;
    }
}
