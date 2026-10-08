<?php

declare(strict_types=1);

namespace Maguari\Server\Cli;

use Maguari\Server\Access\SeedSmtp;
use Maguari\Server\Notifications\SmtpSettingsRules;

/**
 * What check-seed-config prints about the seed file's [smtp] section. The
 * password is never printed, only whether it is set (design section 11.5.1
 * item 8).
 */
final class SeedConfigReport
{
    /**
     * @return string[]
     */
    public static function smtpLines(?SeedSmtp $smtp): array
    {
        if ($smtp === null) {
            return ['SMTP: no [smtp] section'];
        }

        $shown = static fn (string $value): string => $value === '' ? '(not set)' : $value;

        return [
            'SMTP host: ' . $shown($smtp->host),
            'SMTP port: ' . ($smtp->port === '' ? '(not set, ' . SmtpSettingsRules::DEFAULT_PORT . ' is used)' : $smtp->port),
            'SMTP username: ' . $shown($smtp->username),
            'SMTP password: ' . ($smtp->password === '' ? '(not set)' : '(set, hidden)'),
            'SMTP from address: ' . $shown($smtp->from),
        ];
    }
}
