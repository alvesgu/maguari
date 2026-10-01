<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\ClientFailure;
use Maguari\Client\Enroller;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class EnrollerTest extends TestCase
{
    private const TOKEN = 'tttttttttttttttttttttttttttttttttttttttttt0';

    private FakeTransport $transport;
    private Enroller $enroller;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->enroller = new Enroller($this->transport, new FakeClock());
    }

    public function testExchangesTheToken(): void
    {
        $secret = random_bytes(32);
        $this->transport->queue(200, ['client_id' => str_repeat('b', 32), 'secret' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '=')]);

        $credentials = $this->enroller->enroll('HTTPS://Maguari.Example.com/', self::TOKEN);

        $this->assertSame('https://maguari.example.com', $credentials->serverUrl);
        $this->assertSame(str_repeat('b', 32), $credentials->clientId);
        $this->assertSame($secret, $credentials->secret);
        [$request] = $this->transport->requests;
        $this->assertSame('https://maguari.example.com/api/client/enroll', $request['url']);
        $this->assertSame(['protocol_version' => 1, 'client_version' => '0.1.0', 'token' => self::TOKEN], json_decode($request['body'], true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedServers(): array
    {
        return [
            'plain http' => ['http://maguari.example.com'],
            'plain http on 127.0.0.1' => ['http://127.0.0.1:8080'],
            'plain http on a localhost subdomain' => ['http://localhost.example.com'],
            'a path' => ['https://maguari.example.com/maguari'],
            'no scheme' => ['maguari.example.com'],
        ];
    }

    /**
     * @dataProvider refusedServers
     */
    public function testRefusesServersThatAreNotHttpsUnlessLocalhost(string $server): void
    {
        try {
            $this->enroller->enroll($server, self::TOKEN);
            $this->fail('Expected ClientFailure.');
        } catch (ClientFailure $failure) {
            $this->assertStringContainsString('http:// is accepted only for localhost', $failure->getMessage());
        }

        $this->assertSame([], $this->transport->requests, 'The token is never sent.');
    }

    public function testAcceptsPlainHttpOnLocalhost(): void
    {
        $this->transport->queue(200, ['client_id' => str_repeat('b', 32), 'secret' => str_repeat('A', 43)]);

        $this->assertSame('http://localhost:8080', $this->enroller->enroll('http://LOCALHOST:8080', self::TOKEN)->serverUrl);
    }

    public function testInvalidToken(): void
    {
        $this->transport->queue(401, ['error' => 'invalid_token']);

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('The enrollment token is unknown, already used or expired.');

        $this->enroller->enroll('https://maguari.example.com', self::TOKEN);
    }

    public function testAnswerThatIsNotMaguari(): void
    {
        $this->transport->queue(200, ['hello' => 'world']);

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('The server\'s enrollment answer is not valid.');

        $this->enroller->enroll('https://maguari.example.com', self::TOKEN);
    }
}
