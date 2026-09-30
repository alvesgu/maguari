<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpClient;

use InvalidArgumentException;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param float $timeoutSeconds limit for connecting, for each read and for
     *                              the whole response
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        #[\SensitiveParameter]
        public readonly string $body = '',
        public readonly float $timeoutSeconds = 10.0,
    ) {
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('Invalid HTTP header "%s".', $name));
            }
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('The timeout must be positive.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public static function get(string $url, array $headers = [], float $timeoutSeconds = 10.0): self
    {
        return new self('GET', $url, $headers, '', $timeoutSeconds);
    }

    /**
     * A POST with an application/x-www-form-urlencoded body.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public static function postForm(string $url, #[\SensitiveParameter] array $fields, array $headers = []): self
    {
        return new self(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * A POST with a JSON body.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, array $data, array $headers = []): self
    {
        return new self(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
