<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

/**
 * No access token could be obtained. The message is shown to the administrator,
 * so it explains what to fix and never contains a token or other secret.
 */
final class AccessTokenUnavailable extends \RuntimeException
{
}
