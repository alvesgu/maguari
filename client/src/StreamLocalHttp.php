<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\LocalHttpCheck;

/**
 * LocalHttp on PHP's stream sockets (openssl only). It connects to the URL's
 * literal loopback address, never a resolved name (for `localhost`
 * 127.0.0.1, then ::1 if that refuses: see LocalHttpCheck::ADDRESSES), sends a GET with
 * `Connection: close` and reads only the status line, then closes: redirects
 * are never followed and the body never read. Connecting and the TLS
 * handshake take at most LocalHttpCheck::TIMEOUT_SECONDS, and so does the
 * status line after them. Certificates are verified against the host name,
 * with the system's CA store.
 */
final class StreamLocalHttp implements LocalHttp
{
    private const POLL_SECONDS = 0.25;
    private const MAX_STATUS_LINE_BYTES = 8192;
    private const ECONNREFUSED = 111;
    private const ETIMEDOUT = 110;

    /**
     * @param ?string $caFile trusted CA certificates instead of the system's, for tests only
     */
    public function __construct(
        private readonly ?string $caFile = null,
        private readonly float $timeoutSeconds = LocalHttpCheck::TIMEOUT_SECONDS,
    ) {
    }

    public function request(string $url, string $hostName): array|string
    {
        $parts = LocalHttpCheck::parts($url) ?? throw new \InvalidArgumentException('Not a URL of this instance.');
        $start = microtime(true);
        $tls = $parts['scheme'] === 'https';
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $name = $hostName === '' ? $parts['host'] : $hostName;
        $ssl = ['peer_name' => $name, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];

        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $socket = $this->connect(LocalHttpCheck::ADDRESSES[$parts['host']], $port, stream_context_create(['ssl' => $ssl]));

        if (is_string($socket)) {
            return $socket;
        }

        try {
            stream_set_blocking($socket, false);

            if ($tls && ($reason = $this->handshake($socket, $start + $this->timeoutSeconds)) !== null) {
                return $reason;
            }

            $deadline = microtime(true) + $this->timeoutSeconds;
            $authority = $hostName === '' ? $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']) : $hostName;
            $reason = $this->write($socket, "GET {$parts['target']} HTTP/1.1\r\nHost: {$authority}\r\nUser-Agent: Maguari/" . Version::current() . " local check\r\nAccept: */*\r\nConnection: close\r\n\r\n", $deadline);

            if ($reason !== null) {
                return $reason;
            }

            $line = $this->statusLine($socket, $deadline);
        } finally {
            fclose($socket);
        }

        if (!is_string($line) || preg_match('#^HTTP/\d(?:\.\d)? ([1-5]\d\d)(?: |$)#', $line, $matches) !== 1) {
            return is_string($line) ? LocalHttpCheck::REASON_BAD_RESPONSE : $line['reason'];
        }

        return ['status' => (int) $matches[1], 'ms' => (int) round((microtime(true) - $start) * 1000)];
    }

    /**
     * Tries each address while the previous refuses. When a later one cannot
     * be used at all (IPv6 turned off, for example), the earlier refusal is
     * the answer.
     *
     * @param list<string> $addresses
     * @param resource $context
     * @return resource|string the socket, or why there is none
     */
    private function connect(array $addresses, int $port, $context)
    {
        foreach ($addresses as $index => $address) {
            $socket = @stream_socket_client("tcp://{$address}:{$port}", $errorCode, $errorMessage, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

            if ($socket !== false) {
                return $socket;
            }

            $reason = match (true) {
                $errorCode === self::ECONNREFUSED => LocalHttpCheck::REASON_REFUSED,
                $errorCode === self::ETIMEDOUT, stripos((string) $errorMessage, 'timed out') !== false => LocalHttpCheck::REASON_TIMEOUT,
                default => LocalHttpCheck::REASON_CONNECT_FAILED,
            };

            if ($reason !== LocalHttpCheck::REASON_REFUSED) {
                return $index > 0 && $reason === LocalHttpCheck::REASON_CONNECT_FAILED ? LocalHttpCheck::REASON_REFUSED : $reason;
            }
        }

        return LocalHttpCheck::REASON_REFUSED;
    }

    /**
     * @param resource $socket
     */
    private function handshake($socket, float $deadline): ?string
    {
        while (true) {
            $done = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($done === true) {
                return null;
            }

            if ($done === false) {
                return LocalHttpCheck::REASON_TLS_FAILED;
            }

            if (!$this->wait($socket, $deadline, false)) {
                return LocalHttpCheck::REASON_TIMEOUT;
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $request, float $deadline): ?string
    {
        while ($request !== '') {
            $written = @fwrite($socket, $request);

            if ($written === false) {
                return LocalHttpCheck::REASON_CONNECT_FAILED;
            }

            $request = substr($request, $written);

            if ($request !== '' && !$this->wait($socket, $deadline, true)) {
                return LocalHttpCheck::REASON_TIMEOUT;
            }
        }

        return null;
    }

    /**
     * @param resource $socket
     * @return string|array{reason: string} the status line, or why none arrived
     */
    private function statusLine($socket, float $deadline): string|array
    {
        $received = '';

        while (!str_contains($received, "\n") && strlen($received) <= self::MAX_STATUS_LINE_BYTES) {
            $chunk = @fread($socket, self::MAX_STATUS_LINE_BYTES);

            if ($chunk === false || ($chunk === '' && feof($socket))) {
                return ['reason' => LocalHttpCheck::REASON_BAD_RESPONSE];
            }

            $received .= $chunk;

            if ($chunk === '' && !$this->wait($socket, $deadline, false)) {
                return ['reason' => LocalHttpCheck::REASON_TIMEOUT];
            }
        }

        return rtrim(strtok($received, "\n") ?: '', "\r");
    }

    /**
     * @param resource $socket
     * @return bool false when the deadline passed
     */
    private function wait($socket, float $deadline, bool $write): bool
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            return false;
        }

        $wait = min($remaining, self::POLL_SECONDS);
        $read = $write ? null : [$socket];
        $writes = $write ? [$socket] : null;
        $none = null;
        @stream_select($read, $writes, $none, (int) $wait, (int) (fmod($wait, 1) * 1_000_000));

        return true;
    }
}
