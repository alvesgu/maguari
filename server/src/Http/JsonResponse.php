<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The JSON body and headers shared by /api/client/ and /admin/api/.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function write(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
