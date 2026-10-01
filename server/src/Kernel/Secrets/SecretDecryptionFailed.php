<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Secrets;

/**
 * A stored secret could not be decrypted: it was changed, or encrypted with a
 * different key.
 */
final class SecretDecryptionFailed extends \RuntimeException
{
}
