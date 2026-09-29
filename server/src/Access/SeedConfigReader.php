<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Access\Exception\InvalidSeedConfig;

final class SeedConfigReader
{
    public function __construct(
        private readonly string $path = '/etc/maguari/seed.ini',
    ) {
    }

    public function read(): ?SeedConfig
    {
        if (!is_file($this->path)) {
            return null;
        }

        $data = $this->parse();

        if (!isset($data['administrator']) || !is_array($data['administrator'])) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config file "%s" is missing the [administrator] section.',
                $this->path,
            ));
        }

        $administratorName = $this->requireNonEmptyString(
            $data['administrator'],
            'name',
            'administrator.name',
        );

        $administratorEmail = strtolower($this->requireValidEmail(
            $data['administrator'],
            'email',
            'administrator.email',
        ));

        $allowlist = $this->readAllowlist($data, $administratorEmail);

        return new SeedConfig($administratorName, $administratorEmail, $allowlist);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(): array
    {
        $previousHandler = set_error_handler(static function (): bool {
            return true;
        });

        try {
            $data = parse_ini_file($this->path, true, INI_SCANNER_TYPED);
        } finally {
            restore_error_handler();
        }

        if ($data === false) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config file "%s" could not be parsed as INI.',
                $this->path,
            ));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function requireNonEmptyString(array $section, string $key, string $label): string
    {
        $value = $this->requireString($section, $key, $label);

        if ($value === '') {
            throw new InvalidSeedConfig(sprintf(
                'Seed config key "%s" must not be empty.',
                $label,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function requireValidEmail(array $section, string $key, string $label): string
    {
        $value = $this->requireString($section, $key, $label);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config key "%s" is not a valid email address.',
                $label,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function requireString(array $section, string $key, string $label): string
    {
        if (!array_key_exists($key, $section) || $section[$key] === null) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config key "%s" is missing.',
                $label,
            ));
        }

        $value = $section[$key];

        if (!is_string($value)) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config key "%s" must be a string, but was read as %s. This usually happens '
                    . 'when a value such as yes, no, true, false, on, off, none or null is left '
                    . 'unquoted. Quote it in double quotes, for example: %s = "%s".',
                $label,
                get_debug_type($value),
                $key,
                var_export($value, true),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function readAllowlist(array $data, string $administratorEmail): array
    {
        $section = $data['access'] ?? [];

        if (!is_array($section)) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config file "%s" has an invalid [access] section.',
                $this->path,
            ));
        }

        $rawAllowlist = $section['allowlist'] ?? [];

        if (!is_array($rawAllowlist)) {
            $rawAllowlist = [$rawAllowlist];
        }

        $allowlist = [];

        foreach ($rawAllowlist as $index => $entry) {
            $label = sprintf('access.allowlist[%d]', $index);

            if (!is_string($entry)) {
                throw new InvalidSeedConfig(sprintf(
                    'Seed config key "%s" must be a string, but was read as %s. This usually happens '
                        . 'when a value such as yes, no, true, false, on, off, none or null is left '
                        . 'unquoted. Quote it in double quotes, for example: allowlist[] = "%s".',
                    $label,
                    get_debug_type($entry),
                    var_export($entry, true),
                ));
            }

            if (filter_var($entry, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidSeedConfig(sprintf(
                    'Seed config key "%s" is not a valid email address.',
                    $label,
                ));
            }

            $allowlist[] = strtolower($entry);
        }

        $allowlist = array_values(array_unique($allowlist));

        if ($allowlist === []) {
            return [$administratorEmail];
        }

        return $allowlist;
    }
}
