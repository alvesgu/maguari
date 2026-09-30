<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Access;

use Maguari\Server\Access\SetupTokens;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class SetupTokensTest extends TestCase
{
    private TestEnvironment $environment;
    private SetupTokens $tokens;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->tokens = new SetupTokens($this->environment->database, $this->environment->clock);
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    public function testTokenIsValidForOneHour(): void
    {
        $issued = $this->tokens->issue();

        $this->assertSame($this->environment->clock->now() + 3600, $issued->expiresAt);
        $this->environment->clock->advance(3599);
        $this->assertTrue($this->tokens->isValid($issued->token));
        $this->environment->clock->advance(1);
        $this->assertFalse($this->tokens->isValid($issued->token));
    }

    public function testOnlyTheHashIsStored(): void
    {
        $issued = $this->tokens->issue();
        $stored = $this->environment->database->pdo()->query('SELECT token_hash FROM access_setup_tokens')->fetchAll();

        $this->assertSame([['token_hash' => hash('sha256', $issued->token)]], $stored);
    }

    public function testNewTokenInvalidatesThePreviousOne(): void
    {
        $first = $this->tokens->issue();
        $second = $this->tokens->issue();

        $this->assertNotSame($first->token, $second->token);
        $this->assertFalse($this->tokens->isValid($first->token));
        $this->assertTrue($this->tokens->isValid($second->token));
    }

    public function testConsumedTokenIsNoLongerValid(): void
    {
        $issued = $this->tokens->issue();
        $this->tokens->consume($issued->token);

        $this->assertFalse($this->tokens->isValid($issued->token));
    }

    public function testEmptyAndUnknownTokensAreNotValid(): void
    {
        $this->tokens->issue();

        $this->assertFalse($this->tokens->isValid(''));
        $this->assertFalse($this->tokens->isValid('unknown'));
    }
}
