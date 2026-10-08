<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Tls;

/**
 * Reads served certificates with PHP's stream sockets (openssl only). The
 * connection and the handshake together take at most the timeout: the
 * handshake runs non-blocking against a deadline, because the connect
 * timeout does not bound it. Name resolution is not bounded by it; the
 * system resolver's own timeouts apply.
 */
final class StreamTlsCertificateReader implements TlsCertificateReader
{
    /** How long one wait for the server may take, so a stalled write cannot hang. */
    private const POLL_SECONDS = 0.25;

    /**
     * @param ?string $caFile trusted CA certificates instead of the system's, for tests only
     */
    public function __construct(
        private readonly ?string $caFile = null,
    ) {
    }

    public function read(string $host, int $port, bool $verify, float $timeoutSeconds): PeerCertificate
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $ssl = [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
        ];

        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]),
        );

        if ($socket === false) {
            throw new TlsFailure(sprintf('No connection to %s:%d: %s', $host, $port, $errorMessage));
        }

        try {
            $this->handshake($socket, $host, $deadline);
            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            $parsed = $certificate === null ? false : openssl_x509_parse($certificate);
            $expiresAt = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

            if (!is_int($expiresAt)) {
                throw new TlsFailure(sprintf('No readable certificate from %s.', $host));
            }

            return new PeerCertificate($expiresAt);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @throws TlsFailure
     */
    private function handshake($socket, string $host, float $deadline): void
    {
        stream_set_blocking($socket, false);

        while (true) {
            $done = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($done === true) {
                return;
            }

            if ($done === false) {
                throw new TlsFailure(sprintf('The TLS handshake with %s failed.', $host));
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new TlsFailure(sprintf('The TLS handshake with %s timed out.', $host));
            }

            $wait = min($remaining, self::POLL_SECONDS);
            $read = [$socket];
            $none = null;
            @stream_select($read, $none, $none, (int) $wait, (int) (fmod($wait, 1) * 1_000_000));
        }
    }
}
