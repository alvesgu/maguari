<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpProbe;

/**
 * HttpProbe on PHP's stream sockets (openssl only). Connecting and the TLS
 * handshake together take at most the connect limit: the handshake runs
 * non-blocking against a deadline, because the connect timeout does not
 * bound it. Name resolution is not bounded; the system resolver's own
 * timeouts apply. Certificates are verified against the system's CA store.
 */
final class StreamHttpProbe implements HttpProbe
{
    /** How long one wait for the server may take, so a stalled write cannot hang. */
    private const POLL_SECONDS = 0.25;

    /** A status line longer than this is not one. */
    private const MAX_STATUS_LINE_BYTES = 8192;

    private const ECONNREFUSED = 111;
    private const ETIMEDOUT = 110;

    /**
     * @param ?string $caFile trusted CA certificates instead of the system's, for tests only
     */
    public function __construct(
        private readonly ?string $caFile = null,
    ) {
    }

    public function probe(string $url, string $userAgent, float $connectSeconds, float $totalSeconds): HttpProbeAnswer
    {
        $start = microtime(true);
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The URL must have a scheme and a host.');
        }

        $tls = strtolower($parts['scheme']) === 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $target = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $target .= isset($parts['query']) ? '?' . $parts['query'] : '';
        $ssl = [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];

        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $connectSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]),
        );

        if ($socket === false) {
            throw self::connectFailure($host, $port, (int) $errorCode, (string) $errorMessage);
        }

        try {
            stream_set_blocking($socket, false);

            if ($tls) {
                $this->handshake($socket, $host, $start + min($connectSeconds, $totalSeconds));
            }

            $deadline = $start + $totalSeconds;
            $hostHeader = $host . (isset($parts['port']) ? ':' . $port : '');
            $this->write($socket, "GET {$target} HTTP/1.1\r\nHost: {$hostHeader}\r\nUser-Agent: {$userAgent}\r\nAccept: */*\r\nConnection: close\r\n\r\n", $host, $deadline);
            $line = $this->statusLine($socket, $host, $deadline);
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        } finally {
            fclose($socket);
        }

        if (preg_match('#^HTTP/\d(?:\.\d)? ([1-5]\d\d)(?: |$)#', $line, $matches) !== 1) {
            throw new HttpProbeFailure(HttpProbeFailureKind::BadResponse, sprintf('%s did not answer with an HTTP status line.', $host));
        }

        return new HttpProbeAnswer((int) $matches[1], $elapsedMs);
    }

    private static function connectFailure(string $host, int $port, int $errorCode, string $errorMessage): HttpProbeFailure
    {
        $kind = match (true) {
            $errorCode === self::ECONNREFUSED => HttpProbeFailureKind::Refused,
            $errorCode === self::ETIMEDOUT, stripos($errorMessage, 'timed out') !== false => HttpProbeFailureKind::TimedOut,
            // PHP reports a failed lookup with code 0 and getaddrinfo's message.
            $errorCode === 0 && stripos($errorMessage, 'getaddrinfo') !== false => HttpProbeFailureKind::NameNotResolved,
            default => HttpProbeFailureKind::ConnectFailed,
        };

        return new HttpProbeFailure($kind, sprintf('No connection to %s:%d: %s', $host, $port, $errorMessage));
    }

    /**
     * @param resource $socket
     */
    private function handshake($socket, string $host, float $deadline): void
    {
        while (true) {
            $done = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($done === true) {
                return;
            }

            if ($done === false) {
                throw new HttpProbeFailure(HttpProbeFailureKind::TlsFailed, sprintf('The TLS handshake with %s failed.', $host));
            }

            $this->wait($socket, $host, $deadline, 'The TLS handshake with %s timed out.');
        }
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $request, string $host, float $deadline): void
    {
        while ($request !== '') {
            $written = @fwrite($socket, $request);

            if ($written === false) {
                throw new HttpProbeFailure(HttpProbeFailureKind::ConnectFailed, sprintf('%s closed the connection before the request was sent.', $host));
            }

            $request = substr($request, $written);

            if ($request !== '') {
                $this->wait($socket, $host, $deadline, '%s did not take the request in time.', write: true);
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function statusLine($socket, string $host, float $deadline): string
    {
        $received = '';

        while (!str_contains($received, "\n")) {
            $chunk = @fread($socket, self::MAX_STATUS_LINE_BYTES);

            if ($chunk === false || ($chunk === '' && feof($socket))) {
                throw new HttpProbeFailure(HttpProbeFailureKind::BadResponse, sprintf('%s closed the connection without answering.', $host));
            }

            $received .= $chunk;

            if (strlen($received) > self::MAX_STATUS_LINE_BYTES) {
                break;
            }

            if ($chunk === '') {
                $this->wait($socket, $host, $deadline, '%s did not answer in time.');
            }
        }

        return rtrim(strtok($received, "\n") ?: '', "\r");
    }

    /**
     * Waits until the socket can be read (or written), at most until the deadline.
     *
     * @param resource $socket
     */
    private function wait($socket, string $host, float $deadline, string $timeoutMessage, bool $write = false): void
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new HttpProbeFailure(HttpProbeFailureKind::TimedOut, sprintf($timeoutMessage, $host));
        }

        $wait = min($remaining, self::POLL_SECONDS);
        $read = $write ? null : [$socket];
        $writes = $write ? [$socket] : null;
        $none = null;
        @stream_select($read, $writes, $none, (int) $wait, (int) (fmod($wait, 1) * 1_000_000));
    }
}
