<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Events;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class EventOutboxTest extends TestCase
{
    private TestEnvironment $environment;
    private EventOutbox $outbox;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->outbox = new EventOutbox($this->environment->database);
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedEvents(): array
    {
        return $this->environment->database->pdo()
            ->query('SELECT id, type, payload, occurred_at, delivered_at, failed_attempts FROM kernel_events ORDER BY id')
            ->fetchAll();
    }

    public function testRecordsInsideATransactionAsPending(): void
    {
        $event = new Event('monitoring.check_failed', ['instance_id' => 12, 'subject' => 'example.com/ä'], 1_790_000_000);

        $id = $this->environment->database->transaction(fn (): int => $this->outbox->record($event));

        $this->assertSame([[
            'id' => $id,
            'type' => 'monitoring.check_failed',
            'payload' => '{"instance_id":12,"subject":"example.com/ä"}',
            'occurred_at' => 1_790_000_000,
            'delivered_at' => null,
            'failed_attempts' => 0,
        ]], $this->storedEvents());
    }

    public function testRefusesToRecordOutsideATransaction(): void
    {
        try {
            $this->outbox->record(new Event('monitoring.check_failed', [], 1_790_000_000));
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'The event monitoring.check_failed must be recorded inside the transaction of its change.',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $this->storedEvents());
    }

    public function testIsRolledBackWithItsChange(): void
    {
        try {
            $this->environment->database->transaction(function (): void {
                $this->outbox->record(new Event('monitoring.check_failed', [], 1_790_000_000));

                throw new \RuntimeException('The change failed.');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->storedEvents());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'no context' => ['check_failed'];
        yield 'uppercase' => ['Monitoring.check_failed'];
        yield 'two dots' => ['monitoring.check.failed'];
        yield 'trailing underscore' => ['monitoring.check_'];
        yield 'digits' => ['monitoring.check1'];
        yield 'empty' => [''];
    }

    /**
     * @dataProvider invalidTypes
     */
    public function testRejectsInvalidTypes(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Event($type, [], 1_790_000_000);
    }
}
