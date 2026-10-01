<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Chooses the error format by surface, not by the Accept header: JSON under
 * /api/client (machines), Maguari's HTML pages everywhere else (people).
 */
final class SurfaceErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ErrorHandlerInterface $html,
        private readonly ErrorHandlerInterface $clientApi,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if (!self::isClientApi($request)) {
            return ($this->html)($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
        }

        return ($this->clientApi)($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails)
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function isClientApi(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return $path === '/api/client' || str_starts_with($path, '/api/client/');
    }
}
