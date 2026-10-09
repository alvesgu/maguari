<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The runs of one metric that overlap a range, in start order, for charts
 * (design sections 9.1 and 10.1). Runs are not clipped to the range.
 */
final class RunSeries
{
    /**
     * @param list<MetricRun> $runs
     * @param int $maxGapSeconds the longest gap between two runs that is not
     *        an outage, from the same rule that ends runs (RunRule)
     * @param bool $truncated more runs overlap the range than were returned:
     *        these are the newest
     */
    public function __construct(
        public readonly RunQuery $query,
        public readonly array $runs,
        public readonly int $maxGapSeconds,
        public readonly bool $truncated,
    ) {
    }
}
