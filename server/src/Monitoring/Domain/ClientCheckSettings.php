<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * An instance's client checks as the client receives them, with their
 * version (design section 5.2): the first 16 hex characters of the SHA-256
 * of their canonical JSON. Removing a check and adding it back returns to the
 * same version; an instance without checks has a version too.
 */
final class ClientCheckSettings
{
    /**
     * @param list<array<string, string>> $checks in a fixed order
     */
    private function __construct(
        public readonly string $version,
        public readonly array $checks,
    ) {
    }

    /**
     * @param ClientCheck[] $checks
     */
    public static function of(array $checks): self
    {
        $settings = array_map(static fn (ClientCheck $check): array => $check->setting(), $checks);
        usort($settings, static fn (array $a, array $b): int => strcmp(self::json($a), self::json($b)));

        return new self(substr(hash('sha256', self::json($settings)), 0, 16), $settings);
    }

    /**
     * @return array{version: string, checks: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['version' => $this->version, 'checks' => $this->checks];
    }

    /**
     * @param array<mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
