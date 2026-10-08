<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Access\Exception\InvalidSeedConfig;

final class SeedConfigReader
{
    public const DEFAULT_PATH = '/etc/maguari/seed.ini';

    public function __construct(
        private readonly string $path = self::DEFAULT_PATH,
    ) {
    }

    /**
     * MAGUARI_SEED_FILE overrides the path for development and tests only.
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('MAGUARI_SEED_FILE');

        return new self(is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH);
    }

    public function path(): string
    {
        return $this->path;
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

        return new SeedConfig($administratorName, $administratorEmail, $allowlist, $this->readSmtp($data));
    }

    /**
     * The optional [smtp] section, as text. The port may also be left
     * unquoted, which INI_SCANNER_TYPED reads as an integer.
     *
     * @param array<string, mixed> $data
     */
    private function readSmtp(array $data): ?SeedSmtp
    {
        if (!array_key_exists('smtp', $data)) {
            return null;
        }

        $section = $data['smtp'];

        if (!is_array($section)) {
            throw new InvalidSeedConfig(sprintf(
                'Seed config file "%s" has an invalid [smtp] section.',
                $this->path,
            ));
        }

        $text = function (string $key) use ($section): string {
            if (!array_key_exists($key, $section)) {
                return '';
            }

            if ($key === 'port' && is_int($section[$key])) {
                return (string) $section[$key];
            }

            // The usual hint repeats the value, which must not happen for a password.
            if ($key === 'password' && !is_string($section[$key])) {
                throw new InvalidSeedConfig(
                    'Seed config key "smtp.password" must be text. Quote it in double quotes, for example: password = "...".',
                );
            }

            return $this->requireString($section, $key, 'smtp.' . $key);
        };

        return new SeedSmtp($text('host'), $text('port'), $text('username'), $text('password'), $text('from'));
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
