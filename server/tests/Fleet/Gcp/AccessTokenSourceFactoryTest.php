<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet\Gcp;

use InvalidArgumentException;
use Maguari\Server\Fleet\Gcp\AccessTokenSourceFactory;
use Maguari\Server\Fleet\Gcp\ApplicationDefaultCredentialsTokenSource;
use Maguari\Server\Fleet\Gcp\MetadataServerTokenSource;
use Maguari\Server\Tests\Support\FakeHttpClient;
use Maguari\Server\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class AccessTokenSourceFactoryTest extends TestCase
{
    /**
     * @return array<string, array{?string}>
     */
    public static function metadataSettings(): array
    {
        return ['unset' => [null], 'empty' => [''], 'metadata' => ['metadata']];
    }

    /**
     * @dataProvider metadataSettings
     */
    public function testDefaultsToTheMetadataServer(?string $setting): void
    {
        $source = AccessTokenSourceFactory::create($setting, '/home/dev', new FakeHttpClient(), new FixedClock());

        $this->assertInstanceOf(MetadataServerTokenSource::class, $source);
    }

    public function testApplicationDefault(): void
    {
        $source = AccessTokenSourceFactory::create('application-default', '/home/dev', new FakeHttpClient(), new FixedClock());

        $this->assertInstanceOf(ApplicationDefaultCredentialsTokenSource::class, $source);
    }

    public function testApplicationDefaultNeedsHome(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AccessTokenSourceFactory::create('application-default', null, new FakeHttpClient(), new FixedClock());
    }

    public function testRejectsUnknownSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "metadata" or "application-default"');

        AccessTokenSourceFactory::create('/path/to/key.json', '/home/dev', new FakeHttpClient(), new FixedClock());
    }

    public function testReadsTheEnvironment(): void
    {
        putenv(AccessTokenSourceFactory::ENVIRONMENT_VARIABLE . '=application-default');

        try {
            $source = AccessTokenSourceFactory::fromEnvironment(new FakeHttpClient(), new FixedClock());
        } finally {
            putenv(AccessTokenSourceFactory::ENVIRONMENT_VARIABLE);
        }

        $this->assertInstanceOf(ApplicationDefaultCredentialsTokenSource::class, $source);
    }
}
