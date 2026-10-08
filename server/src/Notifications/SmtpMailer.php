<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends email with PHPMailer (design section 13.2). Encryption is never
 * guessed: it follows the settings, and certificates are verified against
 * the system's CA store. PHPMailer's debug output stays off, because its
 * transcript includes the AUTH exchange, which is the password in base64.
 */
final class SmtpMailer implements Mailer
{
    /** For connecting and for each reply. PHPMailer's own default is 300 seconds. */
    public const TIMEOUT_SECONDS = 10;
    public const SENDER_NAME = 'Maguari';

    /**
     * @param array<string, mixed> $tlsOptions extra ssl stream context options,
     *                                         for tests only (a test CA)
     */
    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        private readonly array $tlsOptions = [],
    ) {
    }

    public function send(SmtpSettings $settings, Email $email, string $localHostname): void
    {
        $smtp = new StageRecordingSmtp();
        $smtp->Timelimit = $this->timeoutSeconds;
        $mailer = new PHPMailer(true);
        $mailer->setSMTPInstance($smtp);
        $mailer->isSMTP();
        $mailer->SMTPDebug = SMTP::DEBUG_OFF;
        $mailer->Host = $settings->host;
        $mailer->Port = $settings->port;
        $mailer->Timeout = $this->timeoutSeconds;
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPSecure = match ($settings->encryption) {
            SmtpEncryption::StartTls => PHPMailer::ENCRYPTION_STARTTLS,
            SmtpEncryption::ImplicitTls => PHPMailer::ENCRYPTION_SMTPS,
            SmtpEncryption::None => '',
        };
        $mailer->SMTPAuth = $settings->username !== '';
        $mailer->Username = $settings->username;
        $mailer->Password = $settings->password;
        $mailer->SMTPOptions = $this->tlsOptions === [] ? [] : ['ssl' => $this->tlsOptions];
        // Never $_SERVER['SERVER_NAME'], which PHPMailer would otherwise use.
        $mailer->Hostname = $localHostname;
        $mailer->Helo = $localHostname;
        $mailer->XMailer = self::SENDER_NAME;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        try {
            $mailer->setFrom($settings->fromAddress, self::SENDER_NAME, false);
            $mailer->addAddress($email->recipient);
            $mailer->Subject = $email->subject;
            // quoted_printable_encode() turns a bare \n into =0A, which would
            // join the lines, so the body gets the CRLF line breaks of SMTP.
            $mailer->Body = PHPMailer::normalizeBreaks($email->body, "\r\n");
            $mailer->send();
        } catch (PHPMailerException $exception) {
            throw self::failure($smtp, $exception);
        } finally {
            $mailer->smtpClose();
        }
    }

    private static function failure(StageRecordingSmtp $smtp, PHPMailerException $exception): SendFailure
    {
        $stage = $smtp->failedStage();

        if ($stage === null) {
            return new SendFailure(null, null, sprintf('%s: %s', $exception::class, $exception->getMessage()), $exception);
        }

        $reply = $smtp->failedReply();
        $error = $smtp->failedError();
        $code = SendFailure::replyCode($reply);

        if ($stage === SmtpStage::Authenticate) {
            // The server's text may repeat the username.
            $detail = $code === null ? 'no reply' : 'reply ' . $code;
        } elseif ($reply !== '') {
            $detail = SendFailure::firstLine($reply);
        } else {
            $detail = implode(' ', array_filter([
                $error['error'] ?? '',
                $error['detail'] ?? '',
                $error['smtp_code_ex'] ?? '',
            ], static fn (string $part): bool => $part !== '')) ?: 'no reply';
        }

        return new SendFailure($stage, $code, $stage->value . ': ' . str_replace(["\r", "\n"], ' ', $detail), $exception);
    }
}
