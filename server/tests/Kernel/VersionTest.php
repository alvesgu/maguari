<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel;

use Maguari\Server\Kernel\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testReadsServerVersion(): void
    {
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION')), Version::current());
        $this->assertMatchesRegularExpression('/^[0-9]+\.[0-9]+\.[0-9]+$/D', Version::current());
    }
}
