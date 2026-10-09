<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\RunQuery;
use Maguari\Server\Monitoring\Exception\InvalidRunQuery;
use PHPUnit\Framework\TestCase;

final class RunQueryTest extends TestCase
{
    private const NOW = 1_790_000_000;

    /**
     * @param array<string, list<string>> $parameters
     */
    private function parse(array $parameters): RunQuery
    {
        return RunQuery::parse($parameters, self::NOW);
    }

    /**
     * @param array<string, list<string>> $parameters
     */
    private function assertRefused(array $parameters, string $sentence): void
    {
        try {
            $this->parse($parameters);
            $this->fail('Accepted: ' . json_encode($parameters));
        } catch (InvalidRunQuery $refused) {
            $this->assertSame($sentence, $refused->getMessage());
        }
    }

    public function testDefaultsToTheLast24Hours(): void
    {
        $query = $this->parse(['metric' => ['disk_used_bytes:/']]);

        $this->assertSame('disk_used_bytes:/', $query->metric);
        $this->assertSame(self::NOW - 86_400, $query->from);
        $this->assertSame(self::NOW, $query->to);
    }

    public function testFromDefaultsTo24HoursBeforeTo(): void
    {
        $query = $this->parse(['metric' => ['disk_used_bytes:/'], 'to' => ['1700000000']]);

        $this->assertSame(1_700_000_000 - 86_400, $query->from);
        $this->assertSame(1_700_000_000, $query->to);
    }

    public function testToDefaultsToNow(): void
    {
        $query = $this->parse(['metric' => ['disk_used_bytes:/'], 'from' => ['1789000000']]);

        $this->assertSame(1_789_000_000, $query->from);
        $this->assertSame(self::NOW, $query->to);
    }

    public function testAcceptsEveryStoredKindAToInTheFutureAnd31Days(): void
    {
        foreach (['disk_used_bytes:/', 'disk_total_bytes:/boot/efi', 'certificate_expires_at:*.example.com', 'disk_used_bytes:/a:b'] as $metric) {
            $this->assertSame($metric, $this->parse(['metric' => [$metric]])->metric);
        }

        $query = $this->parse(['metric' => ['disk_used_bytes:/'], 'from' => [(string) self::NOW], 'to' => [(string) (self::NOW + 31 * 86_400)]]);
        $this->assertSame(self::NOW + 31 * 86_400, $query->to);
        $this->assertSame(0, $this->parse(['metric' => ['disk_used_bytes:/'], 'from' => ['0'], 'to' => ['1']])->from);
    }

    public function testUnknownParametersAreIgnored(): void
    {
        $this->assertSame('disk_used_bytes:/', $this->parse(['metric' => ['disk_used_bytes:/'], 'step' => ['60', '120']])->metric);
    }

    public function testEachParameterOnlyOnce(): void
    {
        foreach (['metric', 'from', 'to'] as $name) {
            $parameters = ['metric' => ['disk_used_bytes:/']];
            $parameters[$name] = [$name === 'metric' ? 'disk_used_bytes:/' : '1789000000', $name === 'metric' ? 'disk_total_bytes:/' : '1789000000'];

            $this->assertRefused($parameters, 'Each parameter may be given only once.');
        }
    }

    public function testTheMetricIsRequired(): void
    {
        $sentence = 'metric is required, for example metric=disk_used_bytes:/.';

        $this->assertRefused([], $sentence);
        $this->assertRefused(['metric' => ['']], $sentence);
        // PHP-style arrays are just an unknown name.
        $this->assertRefused(['metric[]' => ['disk_used_bytes:/']], $sentence);
    }

    public function testAMalformedMetric(): void
    {
        $sentence = 'metric must be <kind>:<subject>, for example disk_used_bytes:/.';

        foreach (['disk_used_bytes', 'disk_used_bytes:', "disk_used_bytes:/a\nb", "disk_used_bytes:/\x7f", "disk_used_bytes:/\xff", 'disk_used_bytes:/' . str_repeat('a', 1_100 - 17 + 1)] as $metric) {
            $this->assertRefused(['metric' => [$metric]], $sentence);
        }

        $this->assertSame(1_100, strlen($this->parse(['metric' => ['disk_used_bytes:/' . str_repeat('a', 1_100 - 17)]])->metric));
    }

    public function testAnUnknownKind(): void
    {
        $sentence = 'Unknown metric kind. The kinds are disk_used_bytes, disk_total_bytes, certificate_expires_at.';

        $this->assertRefused(['metric' => ['disk_free:/']], $sentence);
        $this->assertRefused(['metric' => ['DISK_USED_BYTES:/']], $sentence);
    }

    public function testTimesAreDigitsOnly(): void
    {
        $sentence = 'from and to must be Unix seconds, digits only.';

        foreach (['', '-1', '+1', '01', '1.5', '1e9', ' 1', '1 ', 'abc', '2026-10-08', '123456789012'] as $value) {
            $this->assertRefused(['metric' => ['disk_used_bytes:/'], 'from' => [$value]], $sentence);
            $this->assertRefused(['metric' => ['disk_used_bytes:/'], 'to' => [$value]], $sentence);
        }
    }

    public function testFromMustBeBeforeTo(): void
    {
        $this->assertRefused(['metric' => ['disk_used_bytes:/'], 'from' => ['100'], 'to' => ['100']], 'from must be before to.');
        $this->assertRefused(['metric' => ['disk_used_bytes:/'], 'from' => ['101'], 'to' => ['100']], 'from must be before to.');
        $this->assertRefused(['metric' => ['disk_used_bytes:/'], 'from' => [(string) self::NOW]], 'from must be before to.');
    }

    public function testAtMost31Days(): void
    {
        $this->assertRefused(
            ['metric' => ['disk_used_bytes:/'], 'from' => ['0'], 'to' => [(string) (31 * 86_400 + 1)]],
            'The range is longer than 31 days.',
        );
    }
}
