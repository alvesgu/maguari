<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Renders errors under /admin/api/ as {"error": "<code>"}. Exception messages
 * are never included.
 */
final class AdminApiErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $error = match (true) {
            $exception instanceof HttpNotFoundException => AdminApiError::NotFound,
            $exception instanceof HttpMethodNotAllowedException => AdminApiError::MethodNotAllowed,
            default => AdminApiError::ServerError,
        };

        return json_encode(['error' => $error->value], JSON_THROW_ON_ERROR);
    }
}
