<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The local HTTP check's rules and fields (design section 6.1.3), shared so
 * the server validates what it stores and the client validates again what it
 * receives, and both build the same subject.
 *
 * A URL points at the instance itself: `http://` or `https://`, host exactly
 * `127.0.0.1`, `[::1]` or `localhost`, an optional port, a path and query, no
 * user, password or fragment, only printable ASCII and at most 2,048 bytes,
 * in its stored form (scheme and host lowercase, the default port left out,
 * an empty path as "/"). The client connects to the literal address
 * (`localhost` is 127.0.0.1, then ::1 if that refuses, never resolved). An optional host name only sets
 * the Host header and the TLS name; `https://` requires one, so the
 * certificate can be verified against it.
 */
final class LocalHttpCheck
{
    public const TYPE = 'local_http';
    public const MAX_URL_BYTES = 2048;

    /**
     * The loopback hosts a URL may name, and the addresses the client
     * connects to for each, in order: the next only when the connection to
     * the previous is refused, because a service may listen on only one.
     */
    public const ADDRESSES = ['127.0.0.1' => ['127.0.0.1'], '[::1]' => ['[::1]'], 'localhost' => ['127.0.0.1', '[::1]']];

    // Why there was no status (design section 5.2).
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_REFUSED = 'refused';
    public const REASON_TLS_FAILED = 'tls_failed';
    public const REASON_CONNECT_FAILED = 'connect_failed';
    public const REASON_BAD_RESPONSE = 'bad_response';
    public const REASONS = [self::REASON_TIMEOUT, self::REASON_REFUSED, self::REASON_TLS_FAILED, self::REASON_CONNECT_FAILED, self::REASON_BAD_RESPONSE];

    /** Connecting and the TLS handshake together, then again until the status line. */
    public const TIMEOUT_SECONDS = 5;

    /**
     * Whether the URL and host name, as stored, may be checked.
     */
    public static function isValid(string $url, string $hostName): bool
    {
        $parts = self::parts($url);

        if ($parts === null || $url !== self::url($parts)) {
            return false;
        }

        if ($hostName === '') {
            return $parts['scheme'] === 'http';
        }

        return HostName::isValid($hostName);
    }

    /**
     * The check results' subject: the URL, followed by the host name in
     * parentheses when one is set.
     */
    public static function subject(string $url, string $hostName): string
    {
        return $hostName === '' ? $url : "{$url} ({$hostName})";
    }

    /**
     * @return ?array{scheme: string, host: string, port: ?int, target: string}
     *         null when it is not a URL of the instance itself
     */
    public static function parts(string $url): ?array
    {
        if (strlen($url) > self::MAX_URL_BYTES || preg_match('/[^\x21-\x7e]/', $url) === 1 || str_contains($url, '#') || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (!isset(self::ADDRESSES[$host]) || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $target = (($parts['path'] ?? '') === '' ? '/' : $parts['path']) . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port === ($scheme === 'https' ? 443 : 80) ? null : $port, 'target' => $target];
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, target: string} $parts
     */
    public static function url(array $parts): string
    {
        return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] === null ? '' : ':' . $parts['port']) . $parts['target'];
    }
}
