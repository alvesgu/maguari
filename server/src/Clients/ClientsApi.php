<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\InvalidEnrollmentToken;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Shared\Protocol;

/**
 * The Clients context's public interface: enrollment, and later the heartbeat
 * protocol and command delivery. Instances are referred to by their Fleet ID.
 */
final class ClientsApi
{
    /**
     * The server supports the current protocol version and the previous one
     * (design section 4 item 5). Version 1 has no previous one.
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = [Protocol::VERSION];

    private const SECRET_BYTES = 32;

    private readonly EnrollmentTokens $enrollmentTokens;
    private readonly ClientRepository $clients;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
    ) {
        $this->enrollmentTokens = new EnrollmentTokens($database, $clock);
        $this->clients = new ClientRepository($database);
    }

    /**
     * Issues a one-time enrollment token bound to the instance, replacing any
     * earlier one (design section 5.6 item 2).
     */
    public function issueEnrollmentToken(int $instanceId): IssuedEnrollmentToken
    {
        return $this->enrollmentTokens->issue($instanceId);
    }

    /**
     * Exchanges an enrollment token for a client ID and a permanent HMAC secret
     * (design section 5.6 item 3). The instance comes from the token, so a
     * client cannot choose which instance it is. Any earlier client of the
     * instance stops working.
     *
     * @param string $requestBody the JSON body of POST /api/client/enroll
     * @throws InvalidClientRequest
     * @throws UnsupportedProtocol
     * @throws InvalidEnrollmentToken
     */
    public function enroll(string $requestBody): EnrolledClient
    {
        $request = EnrollmentRequest::parse($requestBody, self::SUPPORTED_PROTOCOL_VERSIONS);
        $client = new EnrolledClient(bin2hex(random_bytes(16)), random_bytes(self::SECRET_BYTES));
        $ciphertext = $this->secretBox->encrypt($client->secret);

        $this->database->transaction(function () use ($request, $client, $ciphertext): void {
            $instanceId = $this->enrollmentTokens->consume($request->token)
                ?? throw new InvalidEnrollmentToken('The enrollment token is unknown, already used or expired.');

            $this->clients->replace(
                $client->clientId,
                $instanceId,
                $ciphertext,
                $this->clock->now(),
                $request->clientVersion,
                $request->protocolVersion,
            );
        });

        return $client;
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, EnrollmentState> keyed by instance ID, one entry per ID
     */
    public function enrollmentStates(array $instanceIds): array
    {
        $states = array_fill_keys($instanceIds, EnrollmentState::NotEnrolled);

        foreach ($this->enrollmentTokens->pendingAmong($instanceIds) as $instanceId) {
            $states[$instanceId] = EnrollmentState::WaitingForEnrollment;
        }

        foreach ($this->clients->enrolledAmong($instanceIds) as $instanceId) {
            $states[$instanceId] = EnrollmentState::Enrolled;
        }

        return $states;
    }
}
