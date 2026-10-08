<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * Where in the SMTP conversation sending failed. Each stage has its own
 * sentence (SendFailureSentence).
 */
enum SmtpStage: string
{
    /** Opening the connection and reading the greeting (and, on port 465, the TLS handshake). */
    case Connect = 'connect';
    case StartTls = 'STARTTLS';
    case Authenticate = 'AUTH';
    case Sender = 'MAIL FROM';
    case Recipient = 'RCPT TO';
    case Message = 'DATA';
}
