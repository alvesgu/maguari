<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The JSON responses under /admin/api/. Errors are {"error": "<code>"}, and a
 * bad request also has "message", Maguari's own sentence, because an
 * administrator may call the endpoint by hand.
 */
final class AdminApiResponse
{
    public static function error(ResponseInterface $response, AdminApiError $error, ?string $message = null): ResponseInterface
    {
        $body = ['error' => $error->value];

        if ($message !== null) {
            $body['message'] = $message;
        }

        return JsonResponse::write($response->withStatus($error->status()), $body);
    }
}
