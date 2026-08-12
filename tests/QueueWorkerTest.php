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
}
