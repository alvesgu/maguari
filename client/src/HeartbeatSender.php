<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Protocol;
use Maguari\Shared\Signature;

/**
 * Sends one signed heartbeat (design sections 5.2 and 5.5) with the disk
 * readings. Checks and command results come in later steps; for now those
 * lists are empty.
 */
final class HeartbeatSender
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly DiskUsage $diskUsage,
    ) {
    }

    /**
     * @throws ClientFailure
     */
    public function send(Credentials $credentials): void
    {
        $now = $this->clock->now();
        $body = json_encode([
            'protocol_version' => Protocol::VERSION,
            'client_version' => Version::current(),
            'client_id' => $credentials->clientId,
            'sent_at' => $now,
            'readings' => $this->diskUsage->readings(),
            'checks' => [],
            'command_results' => [],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) $now;
        $nonce = Signature::newNonce();

        $response = $this->transport->post($credentials->serverUrl . Protocol::HEARTBEAT_PATH, [
            Protocol::HEADER_CLIENT => $credentials->clientId,
            Protocol::HEADER_TIMESTAMP => $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_SIGNATURE => Signature::sign($credentials->secret, 'POST', Protocol::HEARTBEAT_PATH, $timestamp, $nonce, $body),
        ], $body);

        // 204 when there is nothing to send back; an empty 200 means the same.
        if ($response->status !== 204 && !($response->status === 200 && $response->body === '')) {
            throw ServerErrors::failure($response, $now);
        }
    }
}
