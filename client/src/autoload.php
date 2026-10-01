<?php

declare(strict_types=1);

// The client has no Composer dependencies (design section 12.1): this maps its
// own namespace and the shared protocol code. The paths follow the repository
// layout; packaging will install shared/ next to the client and adjust them.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Maguari\\Client\\' => __DIR__ . '/',
        'Maguari\\Shared\\' => dirname(__DIR__, 2) . '/shared/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
