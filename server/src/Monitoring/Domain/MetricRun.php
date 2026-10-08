<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * A stored stretch of consecutive equal readings (design section 9.1).
 */
final class MetricRun
{
    /**
     * The daily checks use only runs that ended within this, so a filesystem
     * or certificate that is no longer reported drops out after a day.
     */
    public const RECENT_FOR_SECONDS = 86_400;

    public function __construct(
        public readonly int $id,
        public readonly int|float $value,
        public readonly int $startAt,
        public readonly int $endAt,
    ) {
    }

    public function isRecent(int $at): bool
    {
        return $at - $this->endAt <= self::RECENT_FOR_SECONDS;
    }

    /**
     * RECENT_FOR_SECONDS for sentences, for example "24 hours".
     */
    public static function recentWindow(): string
    {
        return sprintf('%d hours', intdiv(self::RECENT_FOR_SECONDS, 3600));
    }
}
