<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring;

use Maguari\Server\Tests\Support\DomainLayersTestCase;

/**
 * Monitoring's Domain/ is pure (DomainLayersTestCase). The checker's own
 * tests live here.
 */
final class LayersTest extends DomainLayersTestCase
{
    protected static function context(): string
    {
        return 'Monitoring';
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
        ], static::violations($code));
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
            $this->assertSame([], static::violations("<?php {$call}"), $call);
        }

        foreach ($forbidden as $call) {
            $this->assertSame(['gmdate'], static::violations("<?php {$call}"), $call);
        }
    }

}
