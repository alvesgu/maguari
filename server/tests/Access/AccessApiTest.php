<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Access;

use Maguari\Server\Access\Exception\InvalidSetupInput;
use Maguari\Server\Access\Exception\SetupNotAllowed;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class AccessApiTest extends TestCase
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

    public function testSetupCreatesTheAdministratorAndConsumesTheToken(): void
    {
        $access = $this->environment->access;
        $issued = $access->issueSetupToken();

        $this->assertFalse($access->isSetupComplete());

        $administrator = $access->completeSetup($issued->token, '  Jane Doe ', ' Jane@Example.com ', 'correct horse battery', 'correct horse battery');

        $this->assertSame('Jane Doe', $administrator->name);
        $this->assertSame('jane@example.com', $administrator->email);
        $this->assertTrue($access->isSetupComplete());
        $this->assertFalse($access->isSetupTokenValid($issued->token));

        $hash = $this->environment->database->pdo()->query('SELECT password_hash FROM access_administrators')->fetchColumn();
        $this->assertSame('argon2id', password_get_info($hash)['algoName']);
    }

    public function testNoTokenIsIssuedOnceSetupIsComplete(): void
    {
        $this->environment->createAdministrator();

        $this->expectException(SetupNotAllowed::class);
        $this->environment->access->issueSetupToken();
    }

    public function testSetupRejectsAnInvalidToken(): void
    {
        $this->environment->access->issueSetupToken();

        $this->expectException(SetupNotAllowed::class);
        $this->environment->access->completeSetup('wrong', 'Jane', 'jane@example.com', 'correct horse battery', 'correct horse battery');
    }

    public function testSetupTokenCannotBeUsedTwice(): void
    {
        $access = $this->environment->access;
        $issued = $access->issueSetupToken();
        $access->completeSetup($issued->token, 'Jane', 'jane@example.com', 'correct horse battery', 'correct horse battery');

        $this->expectException(SetupNotAllowed::class);
        $access->completeSetup($issued->token, 'Mallory', 'mallory@example.com', 'correct horse battery', 'correct horse battery');
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function invalidSetupInput(): array
    {
        return [
            'empty name' => ['  ', 'jane@example.com', 'correct horse battery', 'correct horse battery', 'name'],
            'name too long' => [str_repeat('a', 101), 'jane@example.com', 'correct horse battery', 'correct horse battery', 'name'],
            'invalid email' => ['Jane', 'not-an-email', 'correct horse battery', 'correct horse battery', 'email'],
            'password too short' => ['Jane', 'jane@example.com', 'elevenchars', 'elevenchars', 'password'],
            'password too long' => ['Jane', 'jane@example.com', str_repeat('a', 1025), str_repeat('a', 1025), 'password'],
            'password not UTF-8' => ['Jane', 'jane@example.com', str_repeat("\xff", 20), str_repeat("\xff", 20), 'password'],
            'passwords differ' => ['Jane', 'jane@example.com', 'correct horse battery', 'correct horse staple', 'password_confirmation'],
        ];
    }

    /**
     * @dataProvider invalidSetupInput
     */
    public function testSetupValidatesInput(string $name, string $email, string $password, string $confirmation, string $field): void
    {
        $access = $this->environment->access;
        $issued = $access->issueSetupToken();

        try {
            $access->completeSetup($issued->token, $name, $email, $password, $confirmation);
            $this->fail('Setup must reject the input.');
        } catch (InvalidSetupInput $exception) {
            $this->assertSame([$field], array_keys($exception->errors));
        }

        $this->assertFalse($access->isSetupComplete());
        $this->assertTrue($access->isSetupTokenValid($issued->token));
    }

    public function testTwelveMultibyteCharactersAreAValidPassword(): void
    {
        $access = $this->environment->access;
        $issued = $access->issueSetupToken();
        $password = str_repeat('ç', 12);

        $access->completeSetup($issued->token, 'Jane', 'jane@example.com', $password, $password);

        $this->assertNotNull($access->authenticate('jane@example.com', $password));
    }

    public function testAuthenticate(): void
    {
        $administrator = $this->environment->createAdministrator();
        $access = $this->environment->access;

        $this->assertSame($administrator->id, $access->authenticate('JANE@example.com', TestEnvironment::ADMINISTRATOR_PASSWORD)?->id);
        $this->assertNull($access->authenticate(TestEnvironment::ADMINISTRATOR_EMAIL, 'wrong password'));
        $this->assertNull($access->authenticate('nobody@example.com', TestEnvironment::ADMINISTRATOR_PASSWORD));
    }
}
