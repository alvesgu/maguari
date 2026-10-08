<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * Sending failed. The stage and reply code pick the administrator's sentence
 * (SendFailureSentence); the detail is for the error log only. The detail
 * never holds the password or, for a refused sign-in, the server's text,
 * which may repeat the username.
 */
final class SendFailure extends \RuntimeException
{
    /**
     * @param SmtpStage|null $stage null when sending failed before any SMTP stage
     * @param int|null $replyCode the server's 4xx or 5xx reply code, when it sent one
     * @param string $detail one line for the error log
     */
    public function __construct(
        public readonly ?SmtpStage $stage,
        public readonly ?int $replyCode,
        public readonly string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /**
     * The reply code at the start of a 4xx or 5xx reply, or null.
     */
    public static function replyCode(string $reply): ?int
    {
        return preg_match('/^([45][0-9]{2})[ -]/', $reply, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The first line of a reply, for the log.
     */
    public static function firstLine(string $reply): string
    {
        return trim(strtok($reply, "\r\n") ?: '');
    }
}
