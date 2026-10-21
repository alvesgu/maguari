<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\Protocol;
use Maguari\Shared\ServiceCheck;
use Maguari\Shared\UnitName;

/**
 * The checks the server asked this client to run, with their version (design
 * section 5.2). Validated again here with the server's own rules, so the
 * client never runs anything else: a check of an unknown type, or one that
 * breaks the rules, is skipped.
 */
final class CheckSettings
{
    /**
     * @param list<array<string, string>> $checks each with its type and its
     *        fields: unit for a service, url and host_name for local HTTP
     * @param int $skipped checks left out because this client cannot run them
     */
    private function __construct(
        public readonly string $version,
        public readonly array $checks,
        public readonly int $skipped = 0,
    ) {
    }

    /**
     * @param mixed $data the check_settings object, as decoded from JSON
     * @return ?self null when it has no valid version or checks list
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['version'] ?? null) || preg_match(Protocol::CHECK_SETTINGS_VERSION_PATTERN, $data['version']) !== 1) {
            return null;
        }

        $checks = $data['checks'] ?? null;

        if (!is_array($checks) || !array_is_list($checks)) {
            return null;
        }

        $valid = [];

        foreach ($checks as $check) {
            $type = is_array($check) ? ($check['type'] ?? null) : null;

            if ($type === ServiceCheck::TYPE && is_string($check['unit'] ?? null) && UnitName::isValid($check['unit'])) {
                $valid[] = ['type' => ServiceCheck::TYPE, 'unit' => $check['unit']];
            } elseif ($type === LocalHttpCheck::TYPE && is_string($check['url'] ?? null) && is_string($check['host_name'] ?? null) && LocalHttpCheck::isValid($check['url'], $check['host_name'])) {
                $valid[] = ['type' => LocalHttpCheck::TYPE, 'url' => $check['url'], 'host_name' => $check['host_name']];
            }
        }

        return new self($data['version'], $valid, count($checks) - count($valid));
    }

    /**
     * @return array{version: string, checks: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['version' => $this->version, 'checks' => $this->checks];
    }
}
