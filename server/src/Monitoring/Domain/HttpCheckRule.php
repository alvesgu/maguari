<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Judges a remote HTTP check (design section 6.2.1). A failed request is
 * tried once more RETRY_AFTER_SECONDS later, and only the second answer
 * counts, so one dropped packet opens no incident.
 */
final class HttpCheckRule
{
    public const RETRY_AFTER_SECONDS = 5;

    /** Connecting and the TLS handshake together, as for the remote certificate check. */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Until the status line has arrived. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * @param bool $retried whether $answer is the second try after a failure
     */
    public static function result(HttpCheck $check, HttpAnswer $answer, int $checkedAt, bool $retried = false): CheckResult
    {
        $outcome = $answer->passes() ? CheckOutcome::Pass : CheckOutcome::Fail;
        $detail = self::sentence($check, $answer);

        if ($outcome === CheckOutcome::Fail && $retried) {
            $detail .= sprintf(' Tried twice, %d seconds apart.', self::RETRY_AFTER_SECONDS);
        }

        return new CheckResult($check->instanceId, HttpCheck::CHECK_NAME, $outcome, $detail, $checkedAt, $check->url);
    }

    private static function sentence(HttpCheck $check, HttpAnswer $answer): string
    {
        if ($answer->status !== null) {
            return sprintf('Answered %d in %s ms.', $answer->status, number_format($answer->elapsedMs));
        }

        $host = (string) parse_url($check->url, PHP_URL_HOST);
        $port = parse_url($check->url, PHP_URL_PORT) ?? (str_starts_with($check->url, 'https:') ? 443 : 80);

        return match ($answer->failure) {
            HttpFailure::NameNotResolved => sprintf('The name %s did not resolve in DNS.', $host),
            HttpFailure::Refused => sprintf('%s refused the connection on port %d.', $host, $port),
            HttpFailure::TimedOut => sprintf('No answer within %d seconds.', self::TIMEOUT_SECONDS),
            HttpFailure::TlsFailed => 'The TLS connection failed: the certificate is not trusted or does not match the hostname, or the handshake failed.',
            HttpFailure::BadResponse => 'The answer was not an HTTP response.',
            HttpFailure::ConnectFailed, null => sprintf('Could not connect to %s on port %d.', $host, $port),
        };
    }
}
