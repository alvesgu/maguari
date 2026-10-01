<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Clients;

use Maguari\Server\Clients\EnrollmentState;
use Maguari\Server\Clients\EnrollmentTokens;
use Maguari\Server\Clients\HeartbeatState;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class ClientsApiTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @return list<array{token_hash: string, instance_id: int, created_at: int, expires_at: int}>
     */
    private function storedTokens(): array
    {
        return $this->environment->database->pdo()->query('SELECT * FROM clients_enrollment_tokens ORDER BY instance_id')->fetchAll();
    }

    public function testIssuesATokenAndStoresOnlyItsHash(): void
    {
        $issued = $this->environment->clients->issueEnrollmentToken(7);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->token);
        $now = $this->environment->clock->now();
        $this->assertSame($now + EnrollmentTokens::LIFETIME_SECONDS, $issued->expiresAt);
        $this->assertSame(3600, EnrollmentTokens::LIFETIME_SECONDS);
        $this->assertEquals([[
            'token_hash' => hash('sha256', $issued->token),
            'instance_id' => 7,
            'created_at' => $now,
            'expires_at' => $issued->expiresAt,
        ]], $this->storedTokens());
    }

    public function testIssuingAgainReplacesTheInstancesToken(): void
    {
        $first = $this->environment->clients->issueEnrollmentToken(7);
        $other = $this->environment->clients->issueEnrollmentToken(8);

        $second = $this->environment->clients->issueEnrollmentToken(7);

        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(
            [hash('sha256', $second->token), hash('sha256', $other->token)],
            array_column($this->storedTokens(), 'token_hash'),
        );
    }

    public function testIssuingDeletesExpiredTokens(): void
    {
        $this->environment->clients->issueEnrollmentToken(7);
        $this->environment->clock->advance(EnrollmentTokens::LIFETIME_SECONDS);

        $this->environment->clients->issueEnrollmentToken(8);

        $this->assertSame([8], array_map('intval', array_column($this->storedTokens(), 'instance_id')));
    }

    public function testEnrollmentStates(): void
    {
        $this->environment->clients->issueEnrollmentToken(7);
        $this->environment->clock->advance(EnrollmentTokens::LIFETIME_SECONDS - 1);

        $this->assertSame(
            [7 => EnrollmentState::WaitingForEnrollment, 8 => EnrollmentState::NotEnrolled],
            $this->environment->clients->enrollmentStates([7, 8]),
        );

        $this->environment->clock->advance(1);

        $this->assertSame([7 => EnrollmentState::NotEnrolled], $this->environment->clients->enrollmentStates([7]));
        $this->assertSame([], $this->environment->clients->enrollmentStates([]));
    }

    private function heartbeat(string $clientId): void
    {
        $this->environment->clients->recordHeartbeat($clientId, json_encode([
            'protocol_version' => 1,
            'client_version' => '0.3.0',
            'client_id' => $clientId,
            'sent_at' => $this->environment->clock->now(),
        ]));
    }

    public function testHeartbeatStatuses(): void
    {
        $this->environment->clients->issueEnrollmentToken(6);
        $client = $this->environment->enrollClient(7);

        $statuses = $this->environment->clients->heartbeatStatuses([5, 6, 7]);

        $this->assertSame([7], array_keys($statuses), 'Only enrolled instances have a heartbeat status.');
        $this->assertSame(HeartbeatState::NoHeartbeatYet, $statuses[7]->state);
        $this->assertNull($statuses[7]->lastHeartbeatAt);
        $this->assertSame('0.1.0', $statuses[7]->clientVersion);

        $this->heartbeat($client->clientId);
        $heartbeatAt = $this->environment->clock->now();
        $this->environment->clock->advance(90);
        $onTime = $this->environment->clients->heartbeatStatuses([7])[7];

        $this->assertSame(HeartbeatState::OnTime, $onTime->state);
        $this->assertSame($heartbeatAt, $onTime->lastHeartbeatAt);
        $this->assertSame(90, $onTime->ageSeconds);
        $this->assertSame('0.3.0', $onTime->clientVersion);

        $this->environment->clock->advance(1);

        $this->assertSame(HeartbeatState::Late, $this->environment->clients->heartbeatStatuses([7])[7]->state);
        $this->assertSame([], $this->environment->clients->heartbeatStatuses([]));
    }
}
