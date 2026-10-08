<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * The stored SMTP settings as the Email page shows them: never the password,
 * only whether one is stored and whether this server's key can decrypt it.
 */
final class SmtpSettingsSummary
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly bool $hasPassword,
        public readonly bool $passwordReadable,
        public readonly string $fromAddress,
        public readonly int $updatedAt,
    ) {
    }

    public function encryption(): SmtpEncryption
    {
        return SmtpEncryption::for($this->host, $this->port);
    }
}
