<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;
use Maguari\Server\Http\ClientApiResponse;
use Maguari\Server\Http\Middleware\ClientSignatureMiddleware;
use Maguari\Shared\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/client/heartbeat (design section 5.2), behind the signature
 * middleware. The answer is 204 with no body, or 200 with the check settings
 * when the client's are out of date (design section 5.2).
 */
final class HeartbeatController
{
    public function __construct(
        private readonly ClientsApi $clients,
    ) {
    }

    public function receive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clientId = $request->getAttribute(ClientSignatureMiddleware::CLIENT_ID);
        assert(is_string($clientId));

        try {
            $body = $this->clients->recordHeartbeat($clientId, (string) $request->getBody());
        } catch (InvalidClientRequest) {
            return ClientApiResponse::error($response, ErrorCode::BadRequest);
        } catch (UnsupportedProtocol) {
            return ClientApiResponse::error($response, ErrorCode::UnsupportedProtocol);
        }

        if ($body !== null) {
            $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        }

        // Nothing to send back, the usual case: 204 with no body (design section 14).
        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
