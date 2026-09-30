<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpClient;

final class HttpResponse
{
    /** @var array<string, string> header names lowercased */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The body decoded as a JSON object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && !array_is_list($data) ? $data : null;
    }
}
