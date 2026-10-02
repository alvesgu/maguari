<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring;

use PHPUnit\Framework\TestCase;

/**
 * Monitoring's Domain/ holds only pure rules and values (design section 3.1
 * item 7): it uses nothing outside Domain/, Monitoring's exceptions and
 * shared/, and no database, network, file or clock functions. The rule is
 * about hidden inputs: gmdate() is allowed only with an explicit timestamp,
 * and date() never, because its output depends on the timezone setting.
 */
final class LayersTest extends TestCase
{
    private const ALLOWED_PREFIXES = [
        'Maguari\\Server\\Monitoring\\Domain\\',
        'Maguari\\Server\\Monitoring\\Exception\\',
        'Maguari\\Shared\\',
    ];

    /** Global classes for databases and network access. */
    private const FORBIDDEN_CLASSES = ['pdo', 'pdostatement', 'sqlite3', 'mysqli', 'datetime', 'datetimeimmutable'];

    /**
     * Global functions that reach a database, the network, files or the
     * clock, or depend on the timezone setting.
     */
    private const FORBIDDEN_FUNCTIONS = [
        'fopen', 'fsockopen', 'pfsockopen', 'file', 'file_get_contents', 'file_put_contents', 'readfile',
        'gethostbyname', 'gethostbynamel', 'dns_get_record', 'checkdnsrr', 'getmxrr', 'get_headers',
        'mail', 'time', 'microtime', 'hrtime', 'date', 'mktime', 'date_create', 'date_create_immutable',
    ];

    /** Allowed only with an explicit timestamp, its second argument. */
    private const NEEDS_TIMESTAMP = 'gmdate';

    private const FORBIDDEN_FUNCTION_PREFIXES = ['curl_', 'socket_', 'stream_', 'sqlite_', 'openssl_'];

    public function testDomainIsPure(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/Monitoring/Domain/*.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertSame([], self::violations((string) file_get_contents($file)), basename($file));
        }
    }

    public function testTheCheckFindsEachKindOfViolation(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Maguari\Server\Monitoring\Domain;
            use Maguari\Server\Kernel\Database\Database;
            use Maguari\Server\Monitoring\Domain\MetricRun;
            use Maguari\Shared\Metric;
            final class Example {
                public function a(): void {
                    $pdo = new \PDO('sqlite::memory:');
                    $socket = stream_socket_client('tcp://example.com:443');
                    $now = time();
                    $local = date('Y-m-d', $at);
                    $run = new \Maguari\Server\Monitoring\Infrastructure\MetricRunRepository();
                    $this->time();
                    Metric::name('a', 'b');
                }
            }
            PHP;

        $this->assertSame([
            'Maguari\\Server\\Kernel\\Database\\Database',
            'PDO',
            'stream_socket_client',
            'time',
            'date',
            'Maguari\\Server\\Monitoring\\Infrastructure\\MetricRunRepository',
        ], self::violations($code));
    }

    public function testGmdateNeedsAnExplicitTimestamp(): void
    {
        $allowed = [
            "gmdate('Y-m-d', \$at);",
            "gmdate('Y-m-d', timestamp: \$at);",
            "gmdate('H:i', max(0, \$at - 60));",
            "\\gmdate('Y-m-d', \$run->endAt);",
        ];
        $forbidden = [
            "gmdate('Y-m-d');",
            "\\gmdate('Y-m-d');",
            "gmdate('Y-m-d', null);",
            "gmdate('Y-m-d', NULL);",
            "gmdate(...['Y-m-d', \$at]);",
        ];

        foreach ($allowed as $call) {
            $this->assertSame([], self::violations("<?php {$call}"), $call);
        }

        foreach ($forbidden as $call) {
            $this->assertSame(['gmdate'], self::violations("<?php {$call}"), $call);
        }
    }

    /**
     * @return list<string> the names the code must not use
     */
    private static function violations(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (mixed $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $violations = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $previousId = is_array($previous) ? $previous[0] : $previous;

            // The namespace itself, method calls and member names are not uses.
            if (in_array($previousId, [T_NAMESPACE, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $name = match ($token[0]) {
                T_NAME_QUALIFIED, T_STRING => $token[1],
                T_NAME_FULLY_QUALIFIED => ltrim($token[1], '\\'),
                default => null,
            };

            if ($name === null) {
                continue;
            }

            if (str_contains($name, '\\')) {
                // Domain/ imports with full names, never relative ones such
                // as Exception\Foo, so a qualified name is a full one.
                if (!self::isAllowed($name)) {
                    $violations[] = $name;
                }

                continue;
            }

            $lower = strtolower($name);
            $next = $tokens[$i + 1] ?? null;
            $isCall = $next === '(';

            if ($isCall && $lower === self::NEEDS_TIMESTAMP) {
                if (!self::hasExplicitTimestamp($tokens, $i + 1)) {
                    $violations[] = $name;
                }
            } elseif (
                ($previousId === T_NEW && in_array($lower, self::FORBIDDEN_CLASSES, true))
                || ($isCall && in_array($lower, self::FORBIDDEN_FUNCTIONS, true))
                || ($isCall && self::hasForbiddenPrefix($lower))
            ) {
                $violations[] = $name;
            } elseif (!$isCall && in_array($lower, self::FORBIDDEN_CLASSES, true) && $previousId !== T_NEW) {
                // A type or a static call on a forbidden class.
                $violations[] = $name;
            }
        }

        return $violations;
    }

    /**
     * Whether the call whose "(" is at $open has a second argument other
     * than null (a null timestamp means now). An unpacked argument list
     * cannot be checked, so it counts as missing.
     *
     * @param list<mixed> $tokens without whitespace and comments
     */
    private static function hasExplicitTimestamp(array $tokens, int $open): bool
    {
        $depth = 0;
        $arguments = [[]];

        for ($i = $open; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $text === ',') {
                $arguments[] = [];

                continue;
            }

            $arguments[count($arguments) - 1][] = $text;
        }

        $unpacked = ($arguments[0][0] ?? null) === '...';
        $timestamp = strtolower(implode('', $arguments[1] ?? []));

        return !$unpacked && $timestamp !== '' && $timestamp !== 'null' && $timestamp !== 'timestamp:null';
    }

    private static function isAllowed(string $name): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function hasForbiddenPrefix(string $function): bool
    {
        foreach (self::FORBIDDEN_FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($function, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
