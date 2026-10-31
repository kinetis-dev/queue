<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use InvalidArgumentException;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\InMemoryFailedJobRecorder;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\QueueEventLog;
use Kinetis\Queue\Tests\Fixtures\RecordingQueueEventListener;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The exhausted path with a FailedJobRecorderInterface bound: record()
 * is a durable write ahead of fail(), so a job given up on is held by
 * the backend, recorded, or both — never neither.
 */
final class QueueWorkerFailedJobRecordingTest extends TestCase
{
    public function test_the_job_is_recorded_before_the_backend_deletes_it(): void
    {
        [$app, $log] = self::appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);

        $failedAtRecordTime = null;
        $recorder = new InMemoryFailedJobRecorder();
        $recorder->onRecord = static function () use ($queue, &$failedAtRecordTime): void {
            $failedAtRecordTime = $queue->failed;
        };

        new QueueWorker($app, $queue, failedJobs: $recorder)->processNext();

        self::assertSame([], $failedAtRecordTime, 'fail() had not run when record() was called');
        self::assertCount(1, $queue->failed);
        self::assertCount(1, $recorder->recorded);
        self::assertCount(1, $log->failedPermanently);
        self::assertSame('failed-1', $log->failedPermanently[0]->failedJobId);
    }

    public function test_a_record_that_throws_settles_nothing_and_propagates(): void
    {
        [$app, $log] = self::appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);

        $recorder = new InMemoryFailedJobRecorder();
        $recorder->shouldThrow = true;

        try {
            new QueueWorker($app, $queue, failedJobs: $recorder)->processNext();
            self::fail('Expected the recorder failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('The failed-job store refused the write.', $e->getMessage());
        }

        self::assertSame([], $queue->failed, 'the backend still holds the job');
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->acked);
        self::assertSame([], $log->failedPermanently);
    }

    public function test_a_fail_that_throws_propagates_and_leaves_the_record(): void
    {
        [$app, $log] = self::appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);
        $queue->failShouldThrow = true;

        $recorder = new InMemoryFailedJobRecorder();

        try {
            new QueueWorker($app, $queue, failedJobs: $recorder)->processNext();
            self::fail('Expected the fail() failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('fail() itself failed', $e->getMessage());
        }

        self::assertCount(1, $recorder->recorded);
        self::assertSame([], $log->failedPermanently);
    }

    public function test_a_stale_fail_reports_the_lost_settlement_and_keeps_the_record(): void
    {
        [$app, $log] = self::appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);
        $queue->failShouldThrowStale = true;

        $recorder = new InMemoryFailedJobRecorder();

        self::assertTrue(new QueueWorker($app, $queue, failedJobs: $recorder)->processNext());

        self::assertCount(1, $recorder->recorded);
        self::assertCount(1, $log->settlementLost);
        self::assertSame([], $log->failedPermanently);
    }

    public function test_the_record_carries_the_bound_queue_as_a_null_connection_and_the_raw_payload(): void
    {
        $queue = new InMemoryQueue();
        $queue->pushSpent(new SensitiveFailingJob(7, 'a@example.com', 'tok-123'), completedAttempts: 2, maxAttempts: 3, queue: 'mail');

        $recorder = new InMemoryFailedJobRecorder();
        $before = time();

        new QueueWorker(self::app(), $queue, failedJobs: $recorder)->processNext(queues: ['mail']);

        self::assertCount(1, $recorder->recorded);
        $job = $recorder->recorded[0];
        self::assertNull($job->connection);
        self::assertSame('mail', $job->queue);
        self::assertSame(SensitiveFailingJob::class, $job->class);
        self::assertSame(['userId' => 7, 'email' => 'a@example.com', 'resetToken' => 'tok-123'], $job->args);
        self::assertSame(3, $job->maxAttempts);
        self::assertSame(3, $job->attempts);
        self::assertSame(RuntimeException::class, $job->exceptionClass);
        self::assertSame('delivery failed', $job->exceptionMessage);
        self::assertGreaterThanOrEqual($before, $job->failedAt);
    }

    public function test_the_record_carries_the_named_connection_the_worker_ran(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'));

        $recorder = new InMemoryFailedJobRecorder();

        new QueueWorker(self::app(), $queue, failedJobs: $recorder, connection: 'jobs')->processNext();

        self::assertSame('jobs', $recorder->recorded[0]->connection);
        self::assertNull($recorder->recorded[0]->maxAttempts, 'a job that deferred to the worker default is recorded that way');
    }

    public function test_a_retried_attempt_is_never_recorded(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 2);

        $recorder = new InMemoryFailedJobRecorder();

        new QueueWorker(self::app(), $queue, failedJobs: $recorder)->processNext();

        self::assertCount(1, $queue->released);
        self::assertSame([], $recorder->recorded);
    }

    public function test_without_a_recorder_the_event_carries_no_record_id(): void
    {
        [$app, $log] = self::appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);

        new QueueWorker($app, $queue)->processNext();

        self::assertCount(1, $queue->failed);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->acked);
        self::assertCount(1, $log->failedPermanently);
        self::assertNull($log->failedPermanently[0]->failedJobId);
    }

    public function test_an_invalid_connection_name_is_rejected_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new QueueWorker(self::app(), new InMemoryQueue(), connection: 'Jobs');
    }

    public function test_a_long_exception_message_is_cut_to_the_bound_on_a_character_boundary(): void
    {
        // 'é' is two bytes, so an odd byte bound falls inside one.
        $job = new FailedJob(null, 'default', FailingJob::class, [], null, 1, RuntimeException::class, 'x' . str_repeat('é', 2000), 0);

        self::assertSame(FailedJob::MAX_EXCEPTION_MESSAGE_BYTES - 1, \strlen($job->exceptionMessage));
        self::assertSame(1, preg_match('//u', $job->exceptionMessage));
    }

    public function test_an_invalid_utf8_exception_message_is_made_writable(): void
    {
        $job = new FailedJob(null, 'default', FailingJob::class, [], null, 1, RuntimeException::class, "bad \xC3\x28 byte", 0);

        self::assertSame(1, preg_match('//u', $job->exceptionMessage));
        self::assertStringStartsWith('bad ', $job->exceptionMessage);
    }

    private static function app(?callable $beforeBoot = null): AppScope
    {
        $app = new AppScope();

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();

        return $app;
    }

    /**
     * @return array{0: AppScope, 1: QueueEventLog}
     */
    private static function appWithEventLog(): array
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingQueueEventListener::class);
        $log = new QueueEventLog();

        $app = self::app(static function (AppScope $app) use ($registry, $log): void {
            $app->instance(EventListenerRegistry::class, $registry);
            $app->instance(QueueEventLog::class, $log);
        });

        return [$app, $log];
    }
}
