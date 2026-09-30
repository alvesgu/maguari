<?php

declare(strict_types=1);

use Maguari\Server\Http\Middleware\SessionMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

// PHP refuses to change session settings once output has been sent, and PHPUnit
// prints before the first test runs. So the settings are applied here, once, and
// every test uses this one session directory.
$sessionPath = sys_get_temp_dir() . '/maguari-test-sessions-' . bin2hex(random_bytes(8));
mkdir($sessionPath, 0700);
SessionMiddleware::configure($sessionPath);
define('MAGUARI_TEST_SESSION_PATH', $sessionPath);

register_shutdown_function(static function () use ($sessionPath): void {
    foreach (glob($sessionPath . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($sessionPath);
});
