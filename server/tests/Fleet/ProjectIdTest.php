<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet;

use Maguari\Server\Fleet\Exception\InvalidProjectId;
use Maguari\Server\Fleet\ProjectId;
use PHPUnit\Framework\TestCase;

final class ProjectIdTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function validIds(): array
    {
        return [
            'plain' => ['my-project', 'my-project'],
            'with digits' => ['project-123456', 'project-123456'],
            'shortest' => ['abcdef', 'abcdef'],
            'longest' => [str_repeat('a', 30), str_repeat('a', 30)],
            'trimmed and lowercased' => ['  My-Project-1 ', 'my-project-1'],
        ];
    }

    /**
     * @dataProvider validIds
     */
    public function testAcceptsValidIds(string $input, string $expected): void
    {
        $this->assertSame($expected, ProjectId::normalize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIds(): array
    {
        return [
            'empty' => [''],
            'too short' => ['abcde'],
            'too long' => [str_repeat('a', 31)],
            'starts with a digit' => ['1project'],
            'starts with a hyphen' => ['-project'],
            'ends with a hyphen' => ['project-'],
            'underscore' => ['my_project'],
            'domain-scoped' => ['example.com:my-project'],
            'path' => ['my-project/zones'],
            'space inside' => ['my project'],
        ];
    }

    /**
     * @dataProvider invalidIds
     */
    public function testRejectsInvalidIds(string $input): void
    {
        $this->expectException(InvalidProjectId::class);

        ProjectId::normalize($input);
    }
}
