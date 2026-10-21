<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\Protocol;

/**
 * Turns a local HTTP result into Maguari's own sentence (design section
 * 6.1.3). Like the remote check, 2xx and 3xx pass: redirects are not followed.
 */
final class LocalHttpCheckRule
{
    /**
     * @param ClientCheck $check the configured check the result is for
     * @param int $checkedAt the server's receive time of the heartbeat
     */
    public static function result(ClientCheck $check, ClientCheckResult $result, int $checkedAt): CheckResult
    {
        $parts = LocalHttpCheck::parts($check->target);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $detail = match (true) {
            $result->status !== null => sprintf('Answered %d in %s ms.', $result->status, number_format((int) $result->elapsedMs)),
            $result->reason === LocalHttpCheck::REASON_REFUSED => sprintf('Nothing answered on port %d of the instance: the connection was refused.', $port),
            $result->reason === LocalHttpCheck::REASON_TIMEOUT => sprintf('No answer within %d seconds.', LocalHttpCheck::TIMEOUT_SECONDS),
            $result->reason === LocalHttpCheck::REASON_TLS_FAILED => sprintf('The TLS connection failed: the certificate is not trusted or does not match %s, or the handshake failed.', $check->hostName),
            $result->reason === LocalHttpCheck::REASON_BAD_RESPONSE => 'The answer was not an HTTP response.',
            $result->reason === Protocol::CHECK_REASON_TIME_LIMIT => 'The client ran out of time for its checks before this one.',
            default => sprintf('Could not connect to port %d of the instance.', $port),
        };

        return new CheckResult($check->instanceId, LocalHttpCheck::TYPE, $result->outcome, $detail, $checkedAt, $check->subject());
    }
}
