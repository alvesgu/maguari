<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * How the connection to the SMTP server is encrypted (design section 13). It
 * follows from the host and port, so it is never stored: port 465 is implicit
 * TLS, any other port needs STARTTLS, and only localhost (a development
 * server) may be unencrypted.
 */
enum SmtpEncryption
{
    case StartTls;
    case ImplicitTls;
    case None;

    public const IMPLICIT_TLS_PORT = 465;

    public static function for(string $host, int $port): self
    {
        if ($port === self::IMPLICIT_TLS_PORT) {
            return self::ImplicitTls;
        }

        return $host === SmtpSettingsRules::LOCALHOST ? self::None : self::StartTls;
    }

    public function label(): string
    {
        return match ($this) {
            self::StartTls => 'STARTTLS',
            self::ImplicitTls => 'TLS from the start (port 465)',
            self::None => 'none (allowed only for localhost)',
        };
    }
}
