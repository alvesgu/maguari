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
     * The first client version that runs local HTTP checks; older ones skip
     * them (design section 6.1.3).
     */
    public const LOCAL_HTTP_SINCE_CLIENT_VERSION = '0.5.0';

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
