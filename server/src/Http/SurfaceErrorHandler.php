<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Chooses the error format by surface, not by the Accept header: JSON under
 * /api/client (machines) and /admin/api (the web app's scripts), Maguari's
 * HTML pages everywhere else (people).
 */
final class SurfaceErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ErrorHandlerInterface $html,
        private readonly ErrorHandlerInterface $clientApi,
        private readonly ErrorHandlerInterface $adminApi,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $handler = match (true) {
            self::isClientApi($request) => $this->clientApi,
            self::isAdminApi($request) => $this->adminApi,
            default => null,
        };

        if ($handler === null) {
            return ($this->html)($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
        }

        return $handler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails)
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function isClientApi(ServerRequestInterface $request): bool
    {
        return self::isUnder($request, '/api/client');
    }

    public static function isAdminApi(ServerRequestInterface $request): bool
    {
        return self::isUnder($request, '/admin/api');
    }

    private static function isUnder(ServerRequestInterface $request, string $prefix): bool
    {
        $path = $request->getUri()->getPath();

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
