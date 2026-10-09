<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Events;

use Maguari\Server\Kernel\Events\DeliveredEvent;
use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\EventDelivery;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class EventDeliveryTest extends TestCase
{
    private TestEnvironment $environment;
    private EventOutbox $outbox;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->outbox = new EventOutbox($this->environment->database);
        $this->environment->database->pdo()->exec('CREATE TABLE example_effects (note TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(string $type, array $payload = []): int
    {
        return $this->environment->database->transaction(
            fn (): int => $this->outbox->record(new Event($type, $payload, $this->environment->clock->now())),
        );
    }

    /**
     * @param list<Subscriber> $subscribers
     */
    private function delivery(array $subscribers): EventDelivery
    {
        return new EventDelivery($this->environment->database, $this->environment->clock, $subscribers);
    }

    private function writeEffect(string $note): void
    {
        $this->environment->database->pdo()->prepare('INSERT INTO example_effects (note) VALUES (?)')->execute([$note]);
    }

    /**
     * @return list<string>
     */
    private function effects(): array
    {
        return $this->environment->database->pdo()->query('SELECT note FROM example_effects ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedEvent(int $id): array
    {
        $statement = $this->environment->database->pdo()->prepare('SELECT delivered_at, failed_attempts FROM kernel_events WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch();
    }

    public function testDeliversInIdOrderToTheSubscribersOfEachType(): void
    {
        $first = $this->record('monitoring.check_failed', ['instance_id' => 1, 'subject' => 'example.com']);
        $second = $this->record('monitoring.check_passed', ['instance_id' => 2]);
        $third = $this->record('monitoring.check_failed', ['instance_id' => 3]);
        $failures = new RecordingSubscriber(['monitoring.check_failed']);
        $everything = new RecordingSubscriber(['monitoring.check_failed', 'monitoring.check_passed']);
        $this->environment->clock->advance(5);

        $report = $this->delivery([$failures, $everything])->deliver();

        $this->assertEquals([
            new DeliveredEvent($first, 'monitoring.check_failed'),
            new DeliveredEvent($second, 'monitoring.check_passed'),
            new DeliveredEvent($third, 'monitoring.check_failed'),
        ], $report->delivered);
        $this->assertSame([], $report->failures);
        $this->assertFalse($report->limitReached);
        $this->assertSame([1, 3], array_map(static fn (Event $event): int => $event->payload['instance_id'], $failures->handled));
        $this->assertSame([1, 2, 3], array_map(static fn (Event $event): int => $event->payload['instance_id'], $everything->handled));
        $this->assertSame('example.com', $failures->handled[0]->payload['subject']);
        $this->assertSame(1_790_000_000, $failures->handled[0]->occurredAt);
        $this->assertSame(['delivered_at' => 1_790_000_005, 'failed_attempts' => 0], $this->storedEvent($first));
    }

    public function testAnEventNobodyHandlesIsStillDeliveredAndOnlyOnce(): void
    {
        $id = $this->record('monitoring.check_failed');
        $delivery = $this->delivery([]);

        $this->assertEquals([new DeliveredEvent($id, 'monitoring.check_failed')], $delivery->deliver()->delivered);
        $this->assertSame([], $delivery->deliver()->delivered);
    }

    public function testEventsWrittenBySubscribersAreDeliveredInTheSameCall(): void
    {
        $this->record('monitoring.check_failed');
        $opener = new RecordingSubscriber(['monitoring.check_failed'], function (): void {
            $this->writeEffect('incident opened');
            $this->outbox->record(new Event('remediation.incident_opened', [], $this->environment->clock->now()));
        });
        $notifier = new RecordingSubscriber(['remediation.incident_opened'], fn () => $this->writeEffect('notification shown'));

        $report = $this->delivery([$opener, $notifier])->deliver();

        $this->assertSame(
            ['monitoring.check_failed', 'remediation.incident_opened'],
            array_map(static fn (DeliveredEvent $event): string => $event->type, $report->delivered),
        );
        $this->assertSame(['incident opened', 'notification shown'], $this->effects());
    }

    public function testAFailingSubscriberRollsBackItsEventAndStopsDelivery(): void
    {
        $first = $this->record('monitoring.check_failed');
        $second = $this->record('monitoring.check_passed');
        $writer = new RecordingSubscriber(['monitoring.check_failed'], function (): void {
            $this->writeEffect('written before the failure');
            $this->outbox->record(new Event('remediation.incident_opened', [], $this->environment->clock->now()));
        });
        $failing = new RecordingSubscriber(['monitoring.check_failed'], static function (): void {
            throw new \RuntimeException('A bug.');
        });
        $later = new RecordingSubscriber(['monitoring.check_passed']);

        $report = $this->delivery([$writer, $failing, $later])->deliver();

        $this->assertSame([], $report->delivered);
        $this->assertCount(1, $report->failures);
        $failure = $report->failures[0];
        $this->assertSame([$first, 'monitoring.check_failed', 'A bug.', 1, false], [
            $failure->id, $failure->type, $failure->exception->getMessage(), $failure->failedAttempts, $failure->skipped,
        ]);
        $this->assertSame([], $this->effects());
        $this->assertSame([], $later->handled);
        $this->assertSame(['delivered_at' => null, 'failed_attempts' => 1], $this->storedEvent($first));
        $this->assertSame(['delivered_at' => null, 'failed_attempts' => 0], $this->storedEvent($second));
        $this->assertSame(
            2,
            (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM kernel_events')->fetchColumn(),
            'The event the failed subscriber wrote was rolled back.',
        );
    }

    public function testAnEventThatFailedOnThreeCallsIsSkippedAndDeliveryGoesOn(): void
    {
        $broken = $this->record('monitoring.check_failed');
        $next = $this->record('monitoring.check_passed');
        $delivery = $this->delivery([
            new RecordingSubscriber(['monitoring.check_failed'], static function (): void {
                throw new \RuntimeException('A bug.');
            }),
        ]);

        $this->assertFalse($delivery->deliver()->failures[0]->skipped);
        $this->assertFalse($delivery->deliver()->failures[0]->skipped);
        $report = $delivery->deliver();

        $this->assertTrue($report->failures[0]->skipped);
        $this->assertSame(3, $report->failures[0]->failedAttempts);
        $this->assertEquals([new DeliveredEvent($next, 'monitoring.check_passed')], $report->delivered);
        $this->assertSame(['delivered_at' => null, 'failed_attempts' => 3], $this->storedEvent($broken));

        $later = $delivery->deliver();
        $this->assertSame([], $later->delivered);
        $this->assertSame([], $later->failures);
    }

    public function testAFailureThatHealsIsDeliveredOnTheNextCall(): void
    {
        $id = $this->record('monitoring.check_failed');
        $attempts = 0;
        $delivery = $this->delivery([
            new RecordingSubscriber(['monitoring.check_failed'], static function () use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new \RuntimeException('Busy.');
                }
            }),
        ]);

        $delivery->deliver();

        $this->assertEquals([new DeliveredEvent($id, 'monitoring.check_failed')], $delivery->deliver()->delivered);
        $this->assertSame(1, $this->storedEvent($id)['failed_attempts']);
    }

    public function testStopsAtTheLimitAndSaysSo(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->record('monitoring.check_failed');
        }

        $delivery = $this->delivery([]);
        $report = $delivery->deliver(2);

        $this->assertCount(2, $report->delivered);
        $this->assertTrue($report->limitReached);

        $rest = $delivery->deliver(2);

        $this->assertCount(1, $rest->delivered);
        $this->assertFalse($rest->limitReached);
    }

    public function testASubscriberLoopStopsAtTheLimit(): void
    {
        $this->record('monitoring.check_failed');
        $looping = new RecordingSubscriber(['monitoring.check_failed'], function (): void {
            $this->outbox->record(new Event('monitoring.check_failed', [], $this->environment->clock->now()));
        });

        $report = $this->delivery([$looping])->deliver(5);

        $this->assertCount(5, $report->delivered);
        $this->assertTrue($report->limitReached);
    }

    public function testExactlyTheLimitIsNotReportedAsReached(): void
    {
        $this->record('monitoring.check_failed');
        $this->record('monitoring.check_failed');

        $this->assertFalse($this->delivery([])->deliver(2)->limitReached);
    }

    public function testPrunesOnlyEventsDeliveredMoreThanSevenDaysAgo(): void
    {
        $old = $this->record('monitoring.check_failed');
        $delivery = $this->delivery([]);
        $delivery->deliver();
        $this->environment->clock->advance(EventDelivery::KEEP_DELIVERED_SECONDS);
        $recent = $this->record('monitoring.check_passed');
        $delivery->deliver();
        $pending = $this->record('monitoring.check_passed');

        $this->assertSame(0, $delivery->pruneDelivered());

        $this->environment->clock->advance(1);

        $this->assertSame(1, $delivery->pruneDelivered());
        $this->assertSame(
            [$recent, $pending],
            array_map('intval', $this->environment->database->pdo()->query('SELECT id FROM kernel_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)),
        );
        $this->assertNotContains($old, [$recent, $pending]);
    }
}
