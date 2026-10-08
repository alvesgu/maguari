<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * One plain-text email to one recipient. The subject stays ASCII, because
 * mbstring is not a server dependency and PHPMailer needs it to encode
 * other headers; the body may be UTF-8.
 */
final class Email
{
    public function __construct(
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }
}
