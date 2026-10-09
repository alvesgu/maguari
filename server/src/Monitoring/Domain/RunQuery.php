<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidRunQuery;
use Maguari\Shared\Metric;

/**
 * Which runs to read: one metric and a time range in Unix seconds (design
 * section 9.1). Both ends of the range are inclusive.
 */
final class RunQuery
{
    public const DEFAULT_RANGE_SECONDS = 86_400;

    public const MAX_RANGE_SECONDS = 31 * 86_400;

    /**
     * The longest kind, the separator and the longest mount point, rounded up.
     */
    public const MAX_METRIC_BYTES = 1_100;

    private function __construct(
        public readonly string $metric,
        public readonly int $from,
        public readonly int $to,
    ) {
    }

    /**
     * @param array<string, list<string>> $parameters every value given for each
     *        name, in order. Unknown names are ignored.
     * @param int $now the default end of the range
     * @throws InvalidRunQuery
     */
    public static function parse(array $parameters, int $now): self
    {
        foreach (['metric', 'from', 'to'] as $name) {
            if (count($parameters[$name] ?? []) > 1) {
                throw new InvalidRunQuery('Each parameter may be given only once.');
            }
        }

        $metric = $parameters['metric'][0] ?? null;

        if ($metric === null || $metric === '') {
            throw new InvalidRunQuery('metric is required, for example metric=disk_used_bytes:/.');
        }

        [$kind, $subject] = Metric::split($metric);

        if (
            $subject === null
            || $subject === ''
            || strlen($metric) > self::MAX_METRIC_BYTES
            // Also refuses text that is not UTF-8, which JSON cannot carry.
            || preg_match('/^[^\x00-\x1f\x7f]*$/Du', $metric) !== 1
        ) {
            throw new InvalidRunQuery('metric must be <kind>:<subject>, for example disk_used_bytes:/.');
        }

        if (!in_array($kind, Readings::STORED_KINDS, true)) {
            throw new InvalidRunQuery('Unknown metric kind. The kinds are ' . implode(', ', Readings::STORED_KINDS) . '.');
        }

        $to = self::seconds($parameters['to'][0] ?? null) ?? $now;
        $from = self::seconds($parameters['from'][0] ?? null) ?? max(0, $to - self::DEFAULT_RANGE_SECONDS);

        if ($from >= $to) {
            throw new InvalidRunQuery('from must be before to.');
        }

        if ($to - $from > self::MAX_RANGE_SECONDS) {
            throw new InvalidRunQuery(sprintf('The range is longer than %d days.', intdiv(self::MAX_RANGE_SECONDS, 86_400)));
        }

        return new self($metric, $from, $to);
    }

    /**
     * @throws InvalidRunQuery
     */
    private static function seconds(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        // At most 11 digits, so the value always fits in an integer.
        if (preg_match('/^(0|[1-9][0-9]{0,10})$/D', $value) !== 1) {
            throw new InvalidRunQuery('from and to must be Unix seconds, digits only.');
        }

        return (int) $value;
    }
}
