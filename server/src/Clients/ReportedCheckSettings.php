<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * What a client's last heartbeat said about its check settings (design
 * section 5.2).
 */
final class ReportedCheckSettings
{
    /**
     * The first client version that runs checks.
     */
    public const SINCE_CLIENT_VERSION = '0.4.0';

    /**
     * The first client version that runs each kind of check, for those that
     * came after SINCE_CLIENT_VERSION: older clients store them but skip
     * them (design section 6.1.3).
     */
    public const TYPE_SINCE_CLIENT_VERSION = ['local_http' => '0.5.0', 'database' => '0.6.0'];

    /**
     * The client version a check type needs, when $clientVersion is older;
     * null when it runs that type.
     */
    public static function versionNeeded(string $type, ?string $clientVersion): ?string
    {
        $since = self::TYPE_SINCE_CLIENT_VERSION[$type] ?? null;

        return $since !== null && $clientVersion !== null && version_compare($clientVersion, $since, '<') ? $since : null;
    }

    /**
     * @param bool $supported whether it sent check_settings_version at all
     * @param ?string $version the version it has; null for none
     */
    public function __construct(
        public readonly bool $supported,
        public readonly ?string $version,
    ) {
    }
}
