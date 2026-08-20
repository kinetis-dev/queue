<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\RecordingLogger;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueWorkerTest extends TestCase
{
    /**
     * @param (callable(AppScope): void)|null $beforeBoot registrations must
     *        happen before boot() locks the container
     */
    private function app(?callable $beforeBoot = null): AppScope
    {
        $app = new AppScope();
        $app->instance(Recorder::class, new Recorder());

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();

        return $app;
    }

    public function test_processNext_returns_false_when_the_queue_is_empty(): void
    {
        $worker = new QueueWorker($this->app(), new InMemoryQueue());

        self::assertFalse($worker->processNext());
    }

    public function test_processNext_invokes_the_job_with_its_autowired_dependency(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello from the queue'));

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame(['hello from the queue'], $app->get(Recorder::class)->messages);
    }

    public function test_a_successful_job_is_acked_not_released(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertCount(1, $queue->acked);
        self::assertSame([], $queue->released);
    }

    public function test_a_failing_job_is_released_not_acked(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertCount(1, $queue->released);
        self::assertSame([], $queue->acked);
    }

    /**
     * A backend's release() can throw StaleJobHandleException when its
     * own conditional transition finds the source entry already gone —
     * a duplicate call, or a retry after a connection failure whose
     * server-side outcome wasn't known at the time. The transition it
     * wanted has already happened through another path, so the worker
     * must treat this as a benign, already-achieved outcome rather than
     * letting it escape processNext() uncaught and crash the loop —
     * the same "one bad outcome must not stop a long-running process"
     * guarantee this class already gives every other job.
     */
    public function test_a_stale_job_handle_on_release_does_not_crash_the_worker(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);
        $queue->releaseShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame([], $queue->released, 'the fixture recorded no release since it threw instead');

        $infoEntries = array_values(array_filter($logger->entries, static fn (array $entry): bool => $entry['level'] === 'info'));
        self::assertCount(1, $infoEntries, 'a benign info-level note, not silence, and not another error');
        self::assertStringContainsString('already released', $infoEntries[0]['message']);
    }

    public function test_a_stale_job_handle_on_release_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 2);
        $queue->push(new RecordingJob('still runs'));
        $queue->releaseShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);
        $worker->processNext();
        $worker->processNext();

        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    public function test_a_failing_job_is_logged_through_the_container_logger(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $logger->entries);
        self::assertStringContainsString('deliberate failure', $logger->entries[0]['message']);
    }

    public function test_a_failing_job_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'));
        $queue->push(new RecordingJob('still runs'));

        $worker = new QueueWorker($app, $queue);
        $worker->processNext();
        $worker->processNext();

        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    public function test_a_worker_checks_queues_in_the_given_priority_order(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('low priority'), queue: 'low');
        $queue->push(new RecordingJob('high priority'), queue: 'high');

        $worker = new QueueWorker($app, $queue);
        $worker->processNext(queues: ['high', 'low']);

        self::assertSame(['high priority'], $app->get(Recorder::class)->messages);
    }

    public function test_a_worker_only_listening_to_one_queue_never_sees_a_job_on_another(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('for another queue'), queue: 'reports');

        $worker = new QueueWorker($app, $queue);

        self::assertFalse($worker->processNext(queues: ['default']));
        self::assertSame([], $app->get(Recorder::class)->messages);
    }

    public function test_a_failing_job_is_released_again_while_attempts_remain_below_the_limit(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 3);

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertCount(1, $queue->released);
        self::assertSame([], $queue->failed);
    }

    public function test_a_failing_job_is_given_up_on_once_max_attempts_is_reached(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 2);

        $worker = new QueueWorker($app, $queue);
        $worker->processNext(); // attempt 1 of 2 — released
        $worker->processNext(); // attempt 2 of 2 — given up on

        self::assertCount(1, $queue->released);
        self::assertCount(1, $queue->failed);
    }

    public function test_a_failing_job_with_no_configuration_anywhere_is_given_up_on_after_one_attempt(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom')); // no per-job maxAttempts

        // Bare 2-arg construction — QueueWorker's own built-in default,
        // not an explicitly-passed one — proving unlimited retries are
        // unreachable even by omission, not just discouraged.
        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertSame([], $queue->released);
        self::assertCount(1, $queue->failed);
    }

    public function test_giving_up_on_a_job_is_logged_with_the_job_data_and_exception(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $logger->entries);
        self::assertStringContainsString('permanently', $logger->entries[0]['message']);
        self::assertStringContainsString('deliberate failure', $logger->entries[0]['message']);
        self::assertSame(FailingJob::class, $logger->entries[0]['context']['job']['class']);
        self::assertSame(['reason' => 'deliberate failure'], $logger->entries[0]['context']['job']['args']);
        self::assertInstanceOf(RuntimeException::class, $logger->entries[0]['context']['exception']);
    }

    public function test_a_worker_level_default_max_attempts_applies_to_a_job_that_did_not_set_its_own(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom')); // no per-job maxAttempts

        $worker = new QueueWorker($this->app(), $queue, defaultMaxAttempts: 1);
        $worker->processNext();

        self::assertSame([], $queue->released);
        self::assertCount(1, $queue->failed);
    }

    public function test_a_jobs_own_max_attempts_overrides_the_worker_level_default(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 5); // explicit, overrides the default below

        $worker = new QueueWorker($this->app(), $queue, defaultMaxAttempts: 1);
        $worker->processNext();

        self::assertCount(1, $queue->released);
        self::assertSame([], $queue->failed);
    }

    public function test_a_worker_level_default_of_zero_means_no_retries_at_all(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom')); // no per-job maxAttempts

        $worker = new QueueWorker($this->app(), $queue, defaultMaxAttempts: 0);
        $worker->processNext();

        self::assertSame([], $queue->released);
        self::assertCount(1, $queue->failed);
    }

    public function test_run_returns_once_stopped_and_finishes_the_job_in_flight(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('first'));
        $queue->push(new RecordingJob('second'));

        $worker = new QueueWorker($app, $queue);

        // Stop from inside the loop, standing in for a signal arriving
        // mid-job: the job in flight still completes, the next never
        // starts, and run() returns instead of looping forever.
        $app->get(Recorder::class)->onRecord = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run(pollTimeoutSeconds: 0);

        self::assertSame(['first'], $app->get(Recorder::class)->messages);
        self::assertSame([1], $queue->acked);
        self::assertTrue($worker->shouldStop());
    }

    public function test_a_worker_stopped_before_it_starts_processes_nothing(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('never runs'));

        $worker = new QueueWorker($app, $queue);
        $worker->stop();
        $worker->run(pollTimeoutSeconds: 0);

        self::assertSame([], $app->get(Recorder::class)->messages);
        self::assertSame(1, $queue->size());
    }

    /**
     * A job that will be retried is still held by the backend with its
     * payload intact, so the entry carries no copy of it.
     */
    public function test_a_retry_does_not_log_the_job_arguments(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new SensitiveFailingJob(4812, 'ana@example.com', 'not-a-real-token'), maxAttempts: 2);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $logger->entries);
        self::assertStringNotContainsString('permanently', $logger->entries[0]['message']);
        self::assertArrayNotHasKey('args', $logger->entries[0]['context']['job']);
        self::assertSame(SensitiveFailingJob::class, $logger->entries[0]['context']['job']['class']);
        self::assertStringNotContainsString('not-a-real-token', json_encode($logger->entries[0]['context']['job'], JSON_THROW_ON_ERROR));
    }

    public function test_giving_up_redacts_the_arguments_marked_sensitive(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new SensitiveFailingJob(4812, 'ana@example.com', 'not-a-real-token'), maxAttempts: 1);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $logger->entries);
        self::assertStringContainsString('permanently', $logger->entries[0]['message']);
        self::assertSame(
            ['userId' => 4812, 'email' => '[redacted]', 'resetToken' => '[redacted]'],
            $logger->entries[0]['context']['job']['args'],
        );
    }

    public function test_a_failure_is_logged_with_the_queue_and_attempt_it_came_from(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), queue: 'reports', maxAttempts: 1);

        (new QueueWorker($app, $queue))->processNext(queues: ['reports']);

        self::assertCount(1, $logger->entries);
        self::assertSame('reports', $logger->entries[0]['context']['job']['queue']);
        self::assertSame(1, $logger->entries[0]['context']['job']['attempts']);
    }
}
