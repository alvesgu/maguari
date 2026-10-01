<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/maguari-client-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0700);
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0700);

            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
