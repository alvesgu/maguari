<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// App::fromEnvironment() already answers 503 when startup fails. This is the
// last resort for anything else: never a stack trace in the response, only a
// plain 503 page, with the details in the error log.
try {
    Maguari\Server\Http\App::fromEnvironment()->run();
} catch (Throwable $exception) {
    error_log(sprintf('Maguari failed before handling the request: %s: %s', $exception::class, $exception->getMessage()));

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo "Maguari is unavailable. The details are in the server's error log.\n";
}
