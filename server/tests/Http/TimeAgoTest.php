<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\TimeAgo;
use PHPUnit\Framework\TestCase;

final class TimeAgoTest extends TestCase
{
    public function testFormats(): void
    {
        $this->assertSame('0 s ago', TimeAgo::format(0));
        $this->assertSame('119 s ago', TimeAgo::format(119));
        $this->assertSame('2 min ago', TimeAgo::format(120));
        $this->assertSame('119 min ago', TimeAgo::format(7199));
        $this->assertSame('2 h ago', TimeAgo::format(7200));
        $this->assertSame('47 h ago', TimeAgo::format(172799));
        $this->assertSame('2 d ago', TimeAgo::format(172800));
    }
}
