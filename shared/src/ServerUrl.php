<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The Maguari server's base URL, as given to issue-setup-token, set-base-url
 * and the client's --server. One rule for all three: HTTPS, or plain HTTP only
 * when the host is localhost (for local development), because secrets and
 * one-time tokens travel to this URL.
 */
final class ServerUrl
{
    /**
     * @return string|null scheme, host and optional port with no trailing slash,
     *                     for example https://maguari.example.com; null when invalid
     */
    public static function normalize(string $url): ?string
    {
        $parts = parse_url($url);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || str_contains($url, '?')
            || str_contains($url, '#')
        ) {
            return null;
        }

        // Hostnames are case-insensitive, so LOCALHOST is localhost.
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme !== 'https' && !($scheme === 'http' && $host === 'localhost')) {
            return null;
        }

        if (preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            return null;
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
