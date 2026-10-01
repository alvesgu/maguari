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
}
