<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * The check settings last received from the server, next to the credentials
 * and written the same way (PrivateFile), so a restart keeps them. A missing,
 * unreadable or invalid file means no settings: the client then reports none
 * and the server sends them again.
 */
final class CheckSettingsFile
{
    private const FILE_NAME = 'check-settings.json';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function path(): string
    {
        return $this->directory . '/' . self::FILE_NAME;
    }

    /**
     * @throws ClientFailure
     */
    public function save(CheckSettings $settings): void
    {
        PrivateFile::write($this->path(), json_encode($settings->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", 'check settings file');
    }

    public function load(): ?CheckSettings
    {
        $path = $this->path();

        if (!is_file($path) || !PrivateFile::isPrivate($path)) {
            return null;
        }

        return CheckSettings::fromArray(json_decode((string) @file_get_contents($path), true));
    }
}
