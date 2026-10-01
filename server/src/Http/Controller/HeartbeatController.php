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
 * middleware. Nothing to send back yet, so the answer is 204 with no body.
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
            $this->clients->recordHeartbeat($clientId, (string) $request->getBody());
        } catch (InvalidClientRequest) {
            return ClientApiResponse::error($response, ErrorCode::BadRequest);
        } catch (UnsupportedProtocol) {
            return ClientApiResponse::error($response, ErrorCode::UnsupportedProtocol);
        }

        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
