<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * Sends one email through an SMTP server. Tests use a fake.
 */
interface Mailer
{
    /**
     * @param string $localHostname this server's name, for EHLO and Message-ID
     * @throws SendFailure
     */
    public function send(SmtpSettings $settings, Email $email, string $localHostname): void;
}
