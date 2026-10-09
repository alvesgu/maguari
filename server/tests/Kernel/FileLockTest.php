<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel;

use Maguari\Server\Kernel\FileLock;
use PHPUnit\Framework\TestCase;

final class FileLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-lock-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $path = $this->directory . '/tick-lock';
        $first = FileLock::tryAcquire($path);

        $this->assertNotNull($first);
        $this->assertNull(FileLock::tryAcquire($path));
        $this->assertSame(0600, fileperms($path) & 0777);

        $first->release();

        $this->assertNotNull(FileLock::tryAcquire($path));
    }

    public function testIsReleasedWhenTheHolderGoesAway(): void
    {
        $path = $this->directory . '/tick-lock';
        $lock = FileLock::tryAcquire($path);
        unset($lock);

        $this->assertNotNull(FileLock::tryAcquire($path));
    }

    public function testFailsClearlyWhenTheFileCannotBeOpened(): void
    {
        $this->expectExceptionMessage('Could not open the lock file ' . $this->directory . '/missing/tick-lock.');

        FileLock::tryAcquire($this->directory . '/missing/tick-lock');
    }
}
