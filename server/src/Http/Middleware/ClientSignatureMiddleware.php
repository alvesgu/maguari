<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\Exception\ClientRejected;
use Maguari\Server\Http\ClientApiResponse;
use Maguari\Shared\ErrorCode;
use Maguari\Shared\Protocol;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HMAC verification and the per-client rate limit for the signed
 * /api/client routes (design sections 5.5 and 10.2). The checks themselves
 * live in the Clients context; this translates HTTP into that call.
 */
final class ClientSignatureMiddleware implements MiddlewareInterface
{
    /** Request attribute holding the authenticated client ID. */
    public const CLIENT_ID = 'maguari.client_id';

    public function __construct(
        private readonly ClientsApi $clients,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (string) $request->getBody();

        if (strlen($body) > Protocol::MAX_BODY_BYTES) {
            return $this->error(ErrorCode::PayloadTooLarge);
        }

        // The signature does not cover a query string, so none is accepted.
        if ($request->getUri()->getQuery() !== '') {
            return $this->error(ErrorCode::BadRequest);
        }

        try {
            $clientId = $this->clients->authenticate(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $request->getHeaderLine(Protocol::HEADER_CLIENT),
                $request->getHeaderLine(Protocol::HEADER_TIMESTAMP),
                $request->getHeaderLine(Protocol::HEADER_NONCE),
                $request->getHeaderLine(Protocol::HEADER_SIGNATURE),
                $body,
            );
        } catch (ClientRejected $rejected) {
            $response = $this->error($rejected->errorCode, $rejected->details);

            return $rejected->errorCode === ErrorCode::RateLimited
                ? $response->withHeader('Retry-After', (string) ClientsApi::RATE_LIMIT_WINDOW_SECONDS)
                : $response;
        }

        return $handler->handle($request->withAttribute(self::CLIENT_ID, $clientId));
    }

    /**
     * @param array<string, scalar> $details
     */
    private function error(ErrorCode $code, array $details = []): ResponseInterface
    {
        return ClientApiResponse::error($this->responseFactory->createResponse(), $code, $details);
    }
}
