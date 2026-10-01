<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Clients\Exception\ClientRejected;
use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\InvalidEnrollmentToken;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretDecryptionFailed;
use Maguari\Shared\ErrorCode;
use Maguari\Shared\Protocol;
use Maguari\Shared\Signature;

/**
 * The Clients context's public interface: enrollment, signed requests and
 * heartbeats, and later command delivery. Instances are referred to by their
 * Fleet ID.
 */
final class ClientsApi
{
    /**
     * The server supports the current protocol version and the previous one
     * (design section 4 item 5). Version 1 has no previous one.
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = [Protocol::VERSION];

    /** Per-client limit on signed requests (design section 10.2). */
    public const RATE_LIMIT_REQUESTS = 20;
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * A heartbeat older than 1.5 times the interval is late, the same factor
     * as for gaps in runs (design section 9.1 item 3).
     *
     * TEMPORARY (MVP): judging heartbeat age is Monitoring's job, and a fixed
     * 90 seconds will be wrong once the interval is configurable per instance.
     * Replace it with Monitoring's heartbeat-age check, based on each
     * instance's interval (design sections 5.2 and 6.2).
     */
    public const LATE_AFTER_SECONDS = Protocol::HEARTBEAT_INTERVAL_SECONDS * 3 / 2;

    private const SECRET_BYTES = 32;
    private const CLIENT_ID_PATTERN = '/^[0-9a-f]{32}$/D';
    private const TIMESTAMP_PATTERN = '/^[0-9]{1,12}$/D';
    private const NONCE_PATTERN = '/^[0-9a-f]{32}$/D';
    private const SIGNATURE_PATTERN = '/^[0-9a-f]{64}$/D';

    private readonly EnrollmentTokens $enrollmentTokens;
    private readonly ClientRepository $clients;
    private readonly Nonces $nonces;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
    ) {
        $this->enrollmentTokens = new EnrollmentTokens($database, $clock);
        $this->clients = new ClientRepository($database);
        $this->nonces = new Nonces($database);
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
     * Verifies a signed request (design section 5.5) and records its nonce.
     * Cheap checks come first, and nothing is written until the signature is
     * verified, so unauthenticated requests never touch the nonce table.
     *
     * @param string $path the request path, without a query string
     * @return string the authenticated client ID
     * @throws ClientRejected
     * @throws SecretDecryptionFailed when the stored secret does not match the
     *                                server's key: a server problem, not the client's
     */
    public function authenticate(
        string $method,
        string $path,
        string $clientId,
        string $timestamp,
        string $nonce,
        string $signature,
        string $body,
    ): string {
        if (
            preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1
            || preg_match(self::TIMESTAMP_PATTERN, $timestamp) !== 1
            || preg_match(self::NONCE_PATTERN, $nonce) !== 1
            || preg_match(self::SIGNATURE_PATTERN, $signature) !== 1
        ) {
            throw new ClientRejected(ErrorCode::Unauthorized);
        }

        $now = $this->clock->now();
        $sentAt = (int) $timestamp;

        if (abs($now - $sentAt) > Protocol::CLOCK_WINDOW_SECONDS) {
            throw new ClientRejected(ErrorCode::ClockSkew, ['server_time' => $now]);
        }

        // Unknown clients and wrong signatures get the same answer.
        $ciphertext = $this->clients->secretCiphertext($clientId) ?? throw new ClientRejected(ErrorCode::Unauthorized);

        if (!Signature::verify($this->secretBox->decrypt($ciphertext), $signature, $method, $path, $timestamp, $nonce, $body)) {
            throw new ClientRejected(ErrorCode::Unauthorized);
        }

        $this->database->transaction(function () use ($clientId, $nonce, $now, $sentAt): void {
            if ($this->nonces->countSince($clientId, $now - self::RATE_LIMIT_WINDOW_SECONDS) >= self::RATE_LIMIT_REQUESTS) {
                throw new ClientRejected(ErrorCode::RateLimited);
            }

            if ($this->nonces->seen($clientId, $nonce)) {
                throw new ClientRejected(ErrorCode::ReplayedRequest);
            }

            // Kept while a replay could still pass the clock check, and at
            // least as long as the rate limit window, so a client whose clock
            // runs behind cannot shorten either.
            $expiresAt = max($sentAt + Protocol::CLOCK_WINDOW_SECONDS, $now + self::RATE_LIMIT_WINDOW_SECONDS);
            $this->nonces->record($clientId, $nonce, $now, $expiresAt);
            $this->nonces->deleteExpired($now);
        });

        return $clientId;
    }

    /**
     * Records a heartbeat from an authenticated client. The time stored is the
     * server's, not the client's sent_at.
     *
     * @throws InvalidClientRequest
     * @throws UnsupportedProtocol
     */
    public function recordHeartbeat(string $clientId, string $requestBody): void
    {
        $heartbeat = HeartbeatRequest::parse($requestBody, $clientId, self::SUPPORTED_PROTOCOL_VERSIONS);
        $this->clients->recordHeartbeat($clientId, $this->clock->now(), $heartbeat->clientVersion, $heartbeat->protocolVersion);
    }

    /**
     * TEMPORARY (MVP): the On time / Late judgment here belongs to
     * Monitoring's heartbeat-age check (see LATE_AFTER_SECONDS). Clients will
     * keep providing the last heartbeat time.
     *
     * @param int[] $instanceIds
     * @return array<int, HeartbeatStatus> keyed by instance ID, for those of
     *         $instanceIds that are enrolled
     */
    public function heartbeatStatuses(array $instanceIds): array
    {
        $now = $this->clock->now();
        $statuses = [];

        foreach ($this->clients->heartbeatsAmong($instanceIds) as $instanceId => $heartbeat) {
            $lastAt = $heartbeat['last_heartbeat_at'];
            $age = $lastAt === null ? null : max(0, $now - $lastAt);
            $state = match (true) {
                $age === null => HeartbeatState::NoHeartbeatYet,
                $age <= self::LATE_AFTER_SECONDS => HeartbeatState::OnTime,
                default => HeartbeatState::Late,
            };
            $statuses[$instanceId] = new HeartbeatStatus($state, $lastAt, $age, $heartbeat['client_version']);
        }

        return $statuses;
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
