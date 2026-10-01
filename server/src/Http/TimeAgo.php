<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

/**
 * Short ages for the web app, for example "45 s ago" or "3 h ago".
 */
final class TimeAgo
{
    public static function format(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' s ago',
            $seconds < 7200 => intdiv($seconds, 60) . ' min ago',
            $seconds < 172800 => intdiv($seconds, 3600) . ' h ago',
            default => intdiv($seconds, 86400) . ' d ago',
        };
    }
}
