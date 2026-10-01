<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet;

use Maguari\Server\Fleet\Exception\InvalidInstanceName;
use Maguari\Server\Fleet\InstanceName;
use PHPUnit\Framework\TestCase;

final class InstanceNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function valid(): array
    {
        return [
            'short' => ['us-central1-a', 'a'],
            'with digits and hyphens' => ['europe-west4-b', 'web-1'],
            '63 characters' => ['asia-southeast1-c', 'a' . str_repeat('b', 62)],
        ];
    }

    /**
     * @dataProvider valid
     */
    public function testAcceptsValidNames(string $zone, string $name): void
    {
        InstanceName::validate($zone, $name);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid(): array
    {
        return [
            'empty name' => ['us-central1-a', ''],
            'uppercase' => ['us-central1-a', 'Web'],
            'starts with a digit' => ['us-central1-a', '1web'],
            'ends with a hyphen' => ['us-central1-a', 'web-'],
            '64 characters' => ['us-central1-a', 'a' . str_repeat('b', 63)],
            'path' => ['us-central1-a', '../web'],
            'trailing newline in name' => ['us-central1-a', "web\n"],
            'trailing newline in zone' => ["us-central1-a\n", 'web'],
            'region instead of zone' => ['us-central1', 'web'],
            'empty zone' => ['', 'web'],
            'zone with a path' => ['us-central1-a/instances', 'web'],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testRejectsInvalidNames(string $zone, string $name): void
    {
        $this->expectException(InvalidInstanceName::class);

        InstanceName::validate($zone, $name);
    }
}
