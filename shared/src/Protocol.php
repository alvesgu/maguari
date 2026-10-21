<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The client/server protocol's fixed values (design sections 4 and 5).
 */
final class Protocol
{
    /**
     * Increments only on breaking changes to the message format (design
     * section 4 item 4).
     */
    public const VERSION = 1;

    public const ENROLL_PATH = '/api/client/enroll';
    public const HEARTBEAT_PATH = '/api/client/heartbeat';

    /** Largest request body the server accepts, in bytes. */
    public const MAX_BODY_BYTES = 65536;

    /** Default heartbeat interval (design section 7.3). */
    public const HEARTBEAT_INTERVAL_SECONDS = 60;

    /**
     * The server rejects a request whose timestamp is further than this from
     * its own clock, in either direction (design section 5.5).
     */
    public const CLOCK_WINDOW_SECONDS = 300;

    /**
     * A check settings version (design section 5.2): the first 16 hex
     * characters of the SHA-256 of the settings' canonical JSON.
     */
    public const CHECK_SETTINGS_VERSION_PATTERN = '/^[0-9a-f]{16}$/D';

    /** The most check results one heartbeat may carry. */
    public const MAX_CHECK_RESULTS = 50;

    /** A local check that did not start before the client's time for checks ran out. */
    public const CHECK_REASON_TIME_LIMIT = 'time_limit';

    // Signed request headers (design section 5.5).
    public const HEADER_CLIENT = 'X-Maguari-Client';
    public const HEADER_TIMESTAMP = 'X-Maguari-Timestamp';
    public const HEADER_NONCE = 'X-Maguari-Nonce';
    public const HEADER_SIGNATURE = 'X-Maguari-Signature';
}
