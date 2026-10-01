<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Shared\ErrorCode;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Renders errors under /api/client/ as {"error": "<code>"}. Exception messages
 * are never included.
 */
final class ClientApiErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $code = match (true) {
            $exception instanceof HttpNotFoundException => ErrorCode::NotFound,
            $exception instanceof HttpMethodNotAllowedException => ErrorCode::MethodNotAllowed,
            $exception instanceof HttpBadRequestException => ErrorCode::BadRequest,
            default => ErrorCode::ServerError,
        };

        return json_encode(['error' => $code->value], JSON_THROW_ON_ERROR);
    }
}
