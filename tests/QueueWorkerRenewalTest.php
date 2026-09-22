<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Error;
use Kinetis\Async\Exception\DeadlockException;
use Kinetis\Container\AppScope;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\Tests\Fixtures\DeadlockingConcurrentJob;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\RecordingLogger;
use Kinetis\Queue\Tests\Fixtures\RenewableInMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\YieldingJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;

/**
 * The reservation a running job holds is renewed for as long as the job
 * runs, and nothing the renewal does reaches the job's own outcome.
 *
 * Every timing here is expressed through the one-second visibility
 * window the fixture reports, which QueueWorker halves into a
 * 0.5-second tick. A job's own duration is then chosen to span a known
 * number of those ticks.
 */
final class QueueWorkerRenewalTest extends TestCase
{
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_a_yielding_job_is_renewed_repeatedly_while_it_runs(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new YieldingJob(1.3));

        (new QueueWorker($app, $queue))->processNext();

        self::assertGreaterThanOrEqual(
            2,
            \count($queue->renewals),
            'a job spanning two 0.5-second ticks must have been renewed at both',
        );
        self::assertSame([1], array_unique($queue->renewals), 'every renewal named the delivery actually in flight');
        self::assertSame([1], $queue->acked);
    }

    public function test_a_job_that_finishes_before_the_first_tick_is_never_renewed(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new RecordingJob('fast'));

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([], $queue->renewals, 'nothing to extend: the delivery was settled well inside its window');
        self::assertSame([1], $queue->acked);
    }

    /**
     * A queue that does not declare the capability gets no heartbeat at
     * all — the instanceof QueueWorker resolves once in its constructor
     * is the whole gate, and RabbitMQ and SyncQueue sit on this side of
     * it.
     */
    public function test_a_queue_without_the_capability_runs_a_long_job_with_no_watcher_at_all(): void
    {
        [$app] = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new YieldingJob(1.3));

        $before = EventLoop::getIdentifiers();

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame($before, EventLoop::getIdentifiers());
        self::assertSame([1], $queue->acked);
    }

    /**
     * A referenced watcher would keep the event loop alive on its own,
     * which is exactly what Kinetis\Async\ConcurrentBatch reads as
     * "nothing is deadlocked" — see the deadlock test below for the
     * consequence this assertion guards against.
     */
    public function test_the_watcher_running_alongside_the_job_is_an_unreferenced_repeat(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new RecordingJob('inspect'));

        $before = EventLoop::getIdentifiers();

        /** @var array<string, array{CallbackType, bool}>|null $during */
        $during = null;

        // Recorder::$onRecord fires from inside the running handler,
        // which is the only moment the heartbeat's watcher exists — a
        // cancelled identifier cannot be asked its type afterwards. Only
        // the first record is read: the queue fixture records its own
        // settlement through the same recorder, after the watcher is
        // gone.
        $recorder->onRecord = static function () use (&$during, $before): void {
            if ($during !== null) {
                return;
            }

            $during = [];

            foreach (array_diff(EventLoop::getIdentifiers(), $before) as $id) {
                $during[$id] = [EventLoop::getType($id), EventLoop::isReferenced($id)];
            }
        };

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame(
            [[CallbackType::Repeat, false]],
            array_values($during ?? []),
            'exactly one watcher belongs to the heartbeat, and a referenced one would mask a job deadlock',
        );
    }

    public function test_no_watcher_survives_processNext(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new YieldingJob(0.6));

        $before = EventLoop::getIdentifiers();

        (new QueueWorker($app, $queue))->processNext();

        self::assertNotSame([], $queue->renewals, 'the watcher really did run before it was cancelled');
        self::assertSame($before, EventLoop::getIdentifiers(), 'no heartbeat identifier outlives the delivery');
    }

    /**
     * The renewal here outlives the job, so the second and third ticks
     * both come due while the first call is still in flight. Without the
     * in-flight guard each would open its own request against the same
     * receipt.
     */
    public function test_a_tick_arriving_while_a_renewal_is_in_flight_is_suppressed(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder, visibilityTimeoutSeconds: 1, renewSeconds: 1.5);
        $queue->push(new YieldingJob(1.2));

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([1], $queue->renewals, 'the ticks at 1.0s and beyond found a renewal already running');
        self::assertSame(0, $queue->overlappingRenewals);
    }

    /**
     * SQS renews and releases with the same ChangeMessageVisibility
     * call, so a renewal still in flight when the worker settles could
     * replace the backoff a delayed release just wrote. The join is what
     * makes the order below the only possible one.
     */
    public function test_an_in_flight_renewal_finishes_before_the_delivery_is_settled(): void
    {
        [$app, $recorder] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder, visibilityTimeoutSeconds: 1, renewSeconds: 0.6);
        $queue->push(new YieldingJob(0.6));

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame(['renew:start', 'job:done', 'renew:end', 'ack'], $recorder->messages);
    }

    /**
     * The same join, against a queue that breaks renew()'s async
     * boundary: the fixture parks with nothing scheduled to resume it,
     * so the event loop runs dry underneath stop()'s own suspension.
     * That is a lifecycle failure rather than a renewal failure — the
     * renewal is still suspended and could resume over a settlement's
     * own write — so it propagates instead of being contained, and the
     * delivery is left for the backend's timeout rather than settled
     * against a reservation the worker can no longer account for. No
     * renewal report either: there was no settlement to report after.
     */
    public function test_a_renewal_that_cannot_be_joined_propagates_and_settles_nothing(): void
    {
        [$app, $recorder, $logger] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->renewNeverReturns = true;
        $queue->push(new YieldingJob(0.6));

        $before = EventLoop::getIdentifiers();

        try {
            (new QueueWorker($app, $queue))->processNext();
            self::fail('a renewal the worker could not join must not be followed by a settlement');
        } catch (Error $e) {
            self::assertStringContainsString(
                'Event loop terminated without resuming',
                $e->getMessage(),
                'the failure reported is the join that never completed',
            );
        } finally {
            // Unwind the parked renewal inside the test that parked it.
            $queue->releaseParkedRenewal();
        }

        self::assertSame(
            ['renew:start', 'job:done'],
            $recorder->messages,
            'the renewal was still in flight when the handler returned, and nothing was settled after it',
        );
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed);
        self::assertSame(
            [],
            $this->entriesMatching($logger, 'Renewing the reservation'),
            'the renewal report follows a settlement attempt, and there was none',
        );
        self::assertSame(
            $before,
            EventLoop::getIdentifiers(),
            'the watcher was cancelled before the join, so nothing of the heartbeat is left armed',
        );
    }

    /**
     * One refused call says nothing about the next, so the heartbeat
     * keeps trying for the rest of the job rather than surrendering a
     * lease that may still be extendable.
     */
    public function test_renewal_failures_are_contained_counted_and_logged_after_the_settlement(): void
    {
        [$app, $recorder, $logger] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->renewShouldFail = true;
        $queue->push(new YieldingJob(1.3));

        self::assertTrue((new QueueWorker($app, $queue))->processNext(), 'a failing renewal is not a failing job');

        $failures = \count($queue->renewals);

        self::assertGreaterThanOrEqual(2, $failures, 'the tick after a failure still fired');
        self::assertSame([1], $queue->acked, 'the job returned, so the delivery was acked');

        $renewalLog = $this->entriesMatching($logger, 'Renewing the reservation');

        self::assertCount(1, $renewalLog, 'one line for the whole delivery, not one per failed tick');
        self::assertSame('error', $renewalLog[0]['level']);
        self::assertStringContainsString("failed {$failures} time(s)", $renewalLog[0]['message']);
        self::assertSame($failures, $renewalLog[0]['context']['renewalFailures']);
        self::assertStringContainsString(
            "renewal {$failures} refused",
            $renewalLog[0]['context']['exception']->getMessage(),
            'the exception kept is the last one, not the first',
        );

        $ack = array_search('ack', $recorder->messages, true);
        $log = array_search('log:error', $recorder->messages, true);

        self::assertIsInt($ack);
        self::assertIsInt($log);
        self::assertGreaterThan($ack, $log, 'the renewal report follows the outcome it does not decide');
    }

    /**
     * A settlement that throws stops the worker, as it always has. The
     * renewal report is written on the way out rather than lost with the
     * scope, and it never becomes the exception a supervisor sees.
     */
    public function test_a_renewal_failure_is_still_logged_when_the_settlement_itself_throws(): void
    {
        [$app, $recorder, $logger] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->renewShouldFail = true;
        $queue->ackShouldThrow = true;
        $queue->push(new YieldingJob(1.3));

        try {
            (new QueueWorker($app, $queue))->processNext();
            self::fail('a settlement this backend refused must still stop the worker');
        } catch (RuntimeException $e) {
            self::assertSame('ack() itself failed', $e->getMessage(), 'the settlement failure stayed the primary one');
        }

        self::assertCount(1, $this->entriesMatching($logger, 'Renewing the reservation'));
    }

    /**
     * The job's own exception decides the outcome exactly as it always
     * has; a failed renewal alongside it changes neither the settlement
     * nor which exception is reported as the job's.
     */
    public function test_a_failing_job_on_a_renewable_queue_still_releases_for_retry(): void
    {
        [$app, $recorder, $logger] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new FailingJob('boom'), maxAttempts: 3);

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([1], $queue->released);
        self::assertSame([], $queue->acked);
        self::assertSame([], $this->entriesMatching($logger, 'Renewing the reservation'));
    }

    /**
     * The heartbeat's watcher is unreferenced, so the loop still runs
     * dry while the batch's caller is parked and the deadlock is
     * reported rather than waited out forever. The worker treats it as
     * the job's own failure, like any other throwable from handle().
     */
    public function test_a_deadlocking_concurrent_job_still_raises_a_deadlock_exception(): void
    {
        [$app, $recorder, $logger] = $this->app();
        $queue = new RenewableInMemoryQueue($recorder);
        $queue->push(new DeadlockingConcurrentJob());

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([1], $queue->failed, 'no attempts left, so the job was given up on');

        $failureLog = $this->entriesMatching($logger, 'failed permanently');

        self::assertCount(1, $failureLog);
        self::assertInstanceOf(DeadlockException::class, $failureLog[0]['context']['exception']);
    }

    /**
     * @return array{0: AppScope, 1: Recorder, 2: RecordingLogger}
     */
    private function app(): array
    {
        $recorder = new Recorder();
        $logger = new RecordingLogger($recorder);

        $app = new AppScope();
        $app->instance(Recorder::class, $recorder);
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        return [$app, $recorder, $logger];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private function entriesMatching(RecordingLogger $logger, string $needle): array
    {
        return array_values(array_filter(
            $logger->entries,
            static fn (array $entry): bool => str_contains($entry['message'], $needle),
        ));
    }
}
