<?php

declare(strict_types=1);

namespace Maguari\Client;

final class TransportResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /**
     * @return array<mixed>|null the decoded JSON object, or null when the body is not one
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
