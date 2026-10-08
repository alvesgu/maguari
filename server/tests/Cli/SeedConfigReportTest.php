<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Cli;

use Maguari\Server\Access\SeedSmtp;
use Maguari\Server\Cli\SeedConfigReport;
use PHPUnit\Framework\TestCase;

final class SeedConfigReportTest extends TestCase
{
    public function testWithoutAnSmtpSection(): void
    {
        $this->assertSame(['SMTP: no [smtp] section'], SeedConfigReport::smtpLines(null));
    }

    public function testThePasswordIsNeverPrinted(): void
    {
        $this->assertSame([
            'SMTP host: smtp.gmail.com',
            'SMTP port: 587',
            'SMTP username: alerts@example.com',
            'SMTP password: (set, hidden)',
            'SMTP from address: alerts@example.com',
        ], SeedConfigReport::smtpLines(new SeedSmtp('smtp.gmail.com', '587', 'alerts@example.com', 'abcd efgh ijkl mnop', 'alerts@example.com')));
    }

    public function testValuesNotSet(): void
    {
        $this->assertSame([
            'SMTP host: localhost',
            'SMTP port: (not set, 587 is used)',
            'SMTP username: (not set)',
            'SMTP password: (not set)',
            'SMTP from address: (not set)',
        ], SeedConfigReport::smtpLines(new SeedSmtp('localhost', '', '', '', '')));
    }
}
