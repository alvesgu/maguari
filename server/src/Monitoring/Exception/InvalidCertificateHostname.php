<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Exception;

/**
 * A hostname that cannot be added for the remote certificate check. The
 * message is a fixed sentence for the administrator.
 */
final class InvalidCertificateHostname extends \RuntimeException
{
}
