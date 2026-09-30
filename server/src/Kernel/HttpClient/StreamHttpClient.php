<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpClient;

/**
 * HTTP client on PHP's built-in stream wrappers. HTTPS needs only the openssl
 * extension, which Ubuntu's PHP builds include, so php-curl is not required.
 */
final class StreamHttpClient implements HttpClient
{
    private const MAX_BODY_BYTES = 10 * 1024 * 1024;

    public function send(HttpRequest $request): HttpResponse
    {
        $deadline = microtime(true) + $request->timeoutSeconds;
        $headerLines = '';

        foreach ($request->headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => $headerLines,
                'content' => $request->body,
                // Return the response for 4xx and 5xx too, so error bodies can be read.
                'ignore_errors' => true,
                'follow_location' => 0,
                // Applies to connecting and to each read.
                'timeout' => $request->timeoutSeconds,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $host = (string) parse_url($request->url, PHP_URL_HOST);
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $stream = fopen($request->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new HttpClientFailure(sprintf('No response from %s: %s', $host, self::shortReason($warning)));
        }

        try {
            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $body = '';

            while (!feof($stream)) {
                if (microtime(true) > $deadline) {
                    throw new HttpClientFailure(sprintf('No complete response from %s: timed out.', $host));
                }

                $chunk = fread($stream, 8192);

                if ($chunk === false || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                    throw new HttpClientFailure(sprintf('No complete response from %s: timed out.', $host));
                }

                $body .= $chunk;

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    throw new HttpClientFailure(sprintf('The response from %s is too large.', $host));
                }
            }
        } finally {
            fclose($stream);
        }

        return self::response(is_array($headers) ? $headers : [], $body, $host);
    }

    /**
     * @param mixed[] $lines raw header lines, starting with the status line
     */
    private static function response(array $lines, string $body, string $host): HttpResponse
    {
        $status = null;
        $headers = [];

        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            // A new status line starts a new header block (for example after 100 Continue).
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        if ($status === null) {
            throw new HttpClientFailure(sprintf('The response from %s has no status line.', $host));
        }

        return new HttpResponse($status, $headers, $body);
    }

    /**
     * The end of PHP's warning, without the URL it starts with.
     */
    private static function shortReason(?string $warning): string
    {
        if ($warning === null) {
            return 'unknown error.';
        }

        $position = strpos($warning, '): ');

        return $position === false ? $warning : substr($warning, $position + 3);
    }
}
