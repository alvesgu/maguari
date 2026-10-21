<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Cli;

use Maguari\Server\Cli\HttpChecksReport;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use PHPUnit\Framework\TestCase;

final class HttpChecksReportTest extends TestCase
{
    public function testOneLinePerCheckAndNothingWhenNoneRan(): void
    {
        $this->assertSame([
            'p/us-east1-b/web: Web app https://www.example.com/: Pass. Answered 200 in 340 ms.',
            'instance 9: Web app https://api.example.com/: Fail. No answer within 10 seconds. Tried twice, 5 seconds apart.',
        ], HttpChecksReport::lines([
            new CheckResult(1, 'remote_http', CheckOutcome::Pass, 'Answered 200 in 340 ms.', 0, 'https://www.example.com/'),
            new CheckResult(9, 'remote_http', CheckOutcome::Fail, 'No answer within 10 seconds. Tried twice, 5 seconds apart.', 0, 'https://api.example.com/'),
        ], [1 => 'p/us-east1-b/web']));
        $this->assertSame([], HttpChecksReport::lines([], []));
    }
}
