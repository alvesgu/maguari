<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;

/**
 * The URL of a remote HTTP check (design section 6.2.1): http or https, a
 * public DNS name, an optional port, path and query. Every rejection has its
 * own sentence.
 */
final class HttpCheckUrl
{
    public const MAX_BYTES = 2048;

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * The URL as stored: scheme and host lowercased, the default port left
     * out and an empty path made "/".
     *
     * @throws InvalidHttpCheck
     */
    public static function normalize(string $input): string
    {
        $url = trim($input);

        if ($url === '') {
            throw new InvalidHttpCheck('Enter a URL, for example https://www.example.com/.');
        }

        if (preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new InvalidHttpCheck('Enter the URL without spaces. Percent-encode other characters, and enter internationalized names in their xn-- form.');
        }

        if (strlen($url) > self::MAX_BYTES) {
            throw new InvalidHttpCheck(sprintf('A URL has at most %s characters.', number_format(self::MAX_BYTES)));
        }

        $parts = preg_match('#^https?://#i', $url) === 1 ? parse_url($url) : false;

        if (!is_array($parts) || !isset($parts['host'])) {
            throw new InvalidHttpCheck('Enter a full URL that starts with https:// or http://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidHttpCheck('Leave out the user name and password: the check sends none.');
        }

        if (str_contains($url, '#')) {
            throw new InvalidHttpCheck('Leave out the # part: browsers never send it to the server.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (!CertificateHostname::isValid($host)) {
            throw new InvalidHttpCheck('The URL needs a full hostname, such as www.example.com: not an IP address or a single name like localhost.');
        }

        if ($host === 'internal' || str_ends_with($host, '.internal')) {
            throw new InvalidHttpCheck('Names ending in .internal belong to Google Cloud\'s internal network, not to public web apps.');
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidHttpCheck('A port is a number from 1 to 65535.');
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $portPart = $port === null || $port === self::DEFAULT_PORTS[$scheme] ? '' : ':' . $port;

        return $scheme . '://' . $host . $portPart . $path . $query;
    }
}
