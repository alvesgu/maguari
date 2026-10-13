<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * The administrator's sentence for a failed send (design section 13.2): fixed
 * text with Maguari's own values, never the server's or PHPMailer's message.
 */
final class SendFailureSentence
{
    public const UNEXPECTED = 'The test email could not be sent. The details are in the server\'s error log.';
    /** For alert emails, sent by the tick, whose output is the log. */
    public const UNEXPECTED_ALERT = 'The email could not be sent for an unexpected reason. The details are in the tick\'s output.';
    /** Reply code for "authentication required" (RFC 4954). */
    private const AUTHENTICATION_REQUIRED = 530;

    /**
     * @param list<string>|string $recipients
     * @param string $unexpected the sentence for a failure outside any SMTP stage
     */
    public static function for(SendFailure $failure, SmtpSettings $settings, array|string $recipients, string $unexpected = self::UNEXPECTED): string
    {
        $recipients = (array) $recipients;
        $host = $settings->host;
        $reply = $failure->replyCode === null ? '' : sprintf(' (reply %d)', $failure->replyCode);

        return match ($failure->stage) {
            SmtpStage::Connect => $settings->encryption === SmtpEncryption::ImplicitTls
                ? sprintf(
                    'Could not connect to %s on port %d with TLS, or it did not answer%s. Check the host and port, '
                        . 'that this server can reach them and that the host name matches the server\'s certificate.',
                    $host,
                    $settings->port,
                    $reply,
                )
                : sprintf(
                    'Could not connect to %s on port %d, or it did not answer%s. Check the host and port, and that this server can reach them.',
                    $host,
                    $settings->port,
                    $reply,
                ),
            SmtpStage::StartTls => sprintf(
                '%s did not set up an encrypted connection, so no password or message was sent. '
                    . 'Check the port (%d uses STARTTLS, %d uses TLS from the start) and that the host name matches the server\'s certificate.',
                $host,
                SmtpSettingsRules::DEFAULT_PORT,
                SmtpEncryption::IMPLICIT_TLS_PORT,
            ),
            SmtpStage::Authenticate => sprintf(
                '%s did not accept the username and password%s. For Gmail, use an app password, not the account password.',
                $host,
                $reply,
            ),
            SmtpStage::Sender => $failure->replyCode === self::AUTHENTICATION_REQUIRED
                ? sprintf('%s requires a username and password.', $host)
                : sprintf('%s refused the sender address %s%s. Use an address this account is allowed to send from.', $host, $settings->fromAddress, $reply),
            SmtpStage::Recipient => count($recipients) === 1
                ? sprintf('%s refused the recipient address %s%s.', $host, $recipients[0], $reply)
                : sprintf('%s refused one of the recipient addresses %s%s.', $host, implode(', ', $recipients), $reply),
            SmtpStage::Message => sprintf('%s refused the message%s.', $host, $reply),
            null => $unexpected,
        };
    }
}
