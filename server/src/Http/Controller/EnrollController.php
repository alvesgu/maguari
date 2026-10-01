<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\InvalidEnrollmentToken;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;
use Maguari\Server\Http\ClientApiResponse;
use Maguari\Shared\ErrorCode;
use Maguari\Shared\Protocol;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/client/enroll: exchanges an enrollment token for the client's
 * permanent credentials (design section 5.6 item 3). Not signed: the client
 * has no secret yet, and the token is the credential.
 */
final class EnrollController
{
    public function __construct(
        private readonly ClientsApi $clients,
    ) {
    }

    public function enroll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (string) $request->getBody();

        if (strlen($body) > Protocol::MAX_BODY_BYTES) {
            return ClientApiResponse::error($response, ErrorCode::PayloadTooLarge);
        }

        try {
            $client = $this->clients->enroll($body);
        } catch (InvalidClientRequest) {
            return ClientApiResponse::error($response, ErrorCode::BadRequest);
        } catch (UnsupportedProtocol) {
            return ClientApiResponse::error($response, ErrorCode::UnsupportedProtocol);
        } catch (InvalidEnrollmentToken) {
            return ClientApiResponse::error($response, ErrorCode::InvalidToken);
        }

        return ClientApiResponse::json($response, [
            'client_id' => $client->clientId,
            'secret' => rtrim(strtr(base64_encode($client->secret), '+/', '-_'), '='),
        ]);
    }
}
