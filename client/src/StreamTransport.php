<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * HTTP on PHP's built-in stream wrappers (php-cli only, no php-curl), with
 * certificate verification, a timeout and no redirects. A little of the
 * server's StreamHttpClient is repeated on purpose: the client package never
 * contains server code (design section 3).
 */
final class StreamTransport implements Transport
{
    private const TIMEOUT_SECONDS = 10;
    // Maguari's answers are tiny; anything bigger is not Maguari.
    private const MAX_BODY_BYTES = 1024 * 1024;

    public function post(string $url, array $headers, string $body): TransportResponse
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $headerLines = "Content-Type: application/json\r\nConnection: close\r\n";

        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $body,
                // Read 4xx and 5xx bodies too: they carry Maguari's error codes.
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new ClientFailure(sprintf('No response from %s: %s', $host, self::shortReason($warning)));
        }

        try {
            $statusLine = (stream_get_meta_data($stream)['wrapper_data'] ?? [])[0] ?? '';
            $responseBody = '';

            while (!feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false || microtime(true) > $deadline || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                    throw new ClientFailure(sprintf('No complete response from %s: timed out.', $host));
                }

                $responseBody .= $chunk;

                if (strlen($responseBody) > self::MAX_BODY_BYTES) {
                    throw new ClientFailure(sprintf('The response from %s is too large to be from Maguari.', $host));
                }
            }
        } finally {
            fclose($stream);
        }

        if (preg_match('#^HTTP/\S+ ([0-9]{3})#', (string) $statusLine, $match) !== 1) {
            throw new ClientFailure(sprintf('The response from %s is not HTTP.', $host));
        }

        return new TransportResponse((int) $match[1], $responseBody);
    }

    /**
     * PHP's warning without the "fopen(url): " prefix, for example
     * "Failed to open stream: Connection refused".
     */
    private static function shortReason(?string $warning): string
    {
        if ($warning === null) {
            return 'unknown error.';
        }

        $position = strpos($warning, '): ');

        return rtrim($position === false ? $warning : substr($warning, $position + 3), '.') . '.';
    }
}
