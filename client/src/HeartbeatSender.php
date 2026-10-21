<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Protocol;
use Maguari\Shared\Signature;

/**
 * Sends one signed heartbeat (design sections 5.2 and 5.5) with the disk and
 * certificate readings and the results of the checks in the stored settings,
 * and stores new check settings when the server sends them. Command results
 * come in a later phase; for now that list is empty.
 */
final class HeartbeatSender
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly DiskUsage $diskUsage,
        private readonly CertificateExpiry $certificates,
        private readonly CheckSettingsFile $checkSettingsFile,
        private readonly LocalChecks $localChecks,
    ) {
    }

    /**
     * @return ?string a line worth logging, when new check settings arrived
     * @throws ClientFailure
     */
    public function send(Credentials $credentials): ?string
    {
        $settings = $this->checkSettingsFile->load();
        $checks = $this->localChecks->run($settings);
        // After the checks, which can take seconds, so the timestamp is fresh.
        $now = $this->clock->now();
        $body = json_encode([
            'protocol_version' => Protocol::VERSION,
            'client_version' => Version::current(),
            'client_id' => $credentials->clientId,
            'sent_at' => $now,
            'readings' => array_merge($this->diskUsage->readings(), $this->certificates->readings()),
            'check_settings_version' => $settings?->version,
            'checks' => $checks,
            'command_results' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) $now;
        $nonce = Signature::newNonce();

        $response = $this->transport->post($credentials->serverUrl . Protocol::HEARTBEAT_PATH, [
            Protocol::HEADER_CLIENT => $credentials->clientId,
            Protocol::HEADER_TIMESTAMP => $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_SIGNATURE => Signature::sign($credentials->secret, 'POST', Protocol::HEARTBEAT_PATH, $timestamp, $nonce, $body),
        ], $body);

        // 204 when there is nothing to send back; an empty 200 means the same.
        if ($response->status === 204 || ($response->status === 200 && $response->body === '')) {
            return null;
        }

        $data = $response->status === 200 ? json_decode($response->body, true) : null;

        if (!is_array($data) || array_is_list($data)) {
            throw ServerErrors::failure($response, $now);
        }

        // Unknown fields are left for newer clients.
        return array_key_exists('check_settings', $data) ? $this->store($data['check_settings']) : null;
    }

    /**
     * @throws ClientFailure when the file cannot be written
     */
    private function store(mixed $data): string
    {
        $settings = CheckSettings::fromArray($data);

        if ($settings === null) {
            return 'Ignored check settings that were not valid; the server sends them again with the next heartbeat.';
        }

        $this->checkSettingsFile->save($settings);
        $count = count($settings->checks);

        return sprintf(
            'Received check settings %s: %d %s.%s',
            $settings->version,
            $count,
            $count === 1 ? 'check' : 'checks',
            $settings->skipped === 0 ? '' : sprintf(' Skipped %d this client cannot run.', $settings->skipped),
        );
    }
}
