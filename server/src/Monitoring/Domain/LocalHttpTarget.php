<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Shared\HostName;
use Maguari\Shared\LocalHttpCheck;

/**
 * The URL and optional host name of a local HTTP check, as an administrator
 * types them (design section 6.1.3). Every rejection has its own sentence;
 * what passes also passes the client's own check (LocalHttpCheck).
 */
final class LocalHttpTarget
{
    /**
     * @return array{string, string} the URL and the host name as stored
     * @throws InvalidClientCheck
     */
    public static function normalize(string $urlInput, string $hostNameInput): array
    {
        $url = trim($urlInput);
        $hostName = strtolower(trim($hostNameInput));

        if ($url === '') {
            throw new InvalidClientCheck('Enter a URL on the instance, for example http://127.0.0.1:8000/health.');
        }

        if (preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new InvalidClientCheck('Enter the URL without spaces. Percent-encode other characters.');
        }

        if (strlen($url) > LocalHttpCheck::MAX_URL_BYTES) {
            throw new InvalidClientCheck(sprintf('A URL has at most %s characters.', number_format(LocalHttpCheck::MAX_URL_BYTES)));
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidClientCheck('Enter a full URL that starts with http:// or https://.');
        }

        if (str_contains($url, '#')) {
            throw new InvalidClientCheck('Leave out the # part: it is never sent to the server.');
        }

        $raw = parse_url($url);

        if (is_array($raw) && (isset($raw['user']) || isset($raw['pass']))) {
            throw new InvalidClientCheck('Leave out the user name and password: the check sends none.');
        }

        $parts = LocalHttpCheck::parts($url);

        if ($parts === null) {
            throw new InvalidClientCheck('The URL must point at the instance itself: its host must be 127.0.0.1, [::1] or localhost. To reach a site by its name, put the name in Host name.');
        }

        if ($hostName !== '' && !HostName::isValid($hostName)) {
            throw new InvalidClientCheck('Enter the host name as visitors use it, for example geonode.example.com: a full name, not an IP address or localhost.');
        }

        if ($hostName === '' && $parts['scheme'] === 'https') {
            throw new InvalidClientCheck('An https:// URL needs a host name, so the certificate can be checked against it.');
        }

        $stored = LocalHttpCheck::url($parts);

        if (!LocalHttpCheck::isValid($stored, $hostName)) {
            throw new \LogicException('A normalized local HTTP check fails the shared rules.');
        }

        return [$stored, $hostName];
    }
}
