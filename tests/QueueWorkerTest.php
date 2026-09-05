<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\MalformedQueuedJobDataException;
use Kinetis\Queue\Exception\StaleJobHandleException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\JobSettlement;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\Tests\Fixtures\DisposalCallbackHolder;
use Kinetis\Queue\Tests\Fixtures\DisposalFailingJob;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\FailingJobWithFailingDisposal;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\PresetQueuedJobQueue;
use Kinetis\Queue\Tests\Fixtures\QueueEventLog;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\RecordingLogger;
use Kinetis\Queue\Tests\Fixtures\RecordingQueueEventListener;
use Kinetis\Queue\Tests\Fixtures\CapturedScopeHolder;
use Kinetis\Queue\Tests\Fixtures\ScopeCapturingViaStaticJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use Kinetis\Queue\Tests\Fixtures\SequencedPopQueue;
use Kinetis\Queue\Tests\Fixtures\ThrowingLogger;
use Kinetis\Queue\Tests\Fixtures\ThrowingQueueEventListener;
use Kinetis\Queue\Tests\Fixtures\ThrowingTelemetry;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueWorkerTest extends TestCase
{
    /**
     * Telemetry::global() is a real per-process singleton — any test that
     * swaps in a custom backend must restore a clean one afterward, or a
     * later, unrelated test would silently observe it.
     */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_a_negative_default_max_attempts_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$defaultMaxAttempts must not be negative');

        new QueueWorker($this->app(), new InMemoryQueue(), -1);
    }

    public function test_a_negative_poll_timeout_throws_before_the_loop_starts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$pollTimeoutSeconds must be a finite, positive number');

        (new QueueWorker($this->app(), new InMemoryQueue()))->run(-1);
    }

    /**
     * KINETIS-64: QueueInterface::pop()'s own 0 means "block with no
     * deadline at all" — genuinely useful for a one-shot pop(), but
     * handed to run()'s own loop it would trap the worker inside pop()
     * forever on an idle queue, since SIGTERM/SIGINT only sets a flag
     * the loop can never get back to checking. run() must reject it
     * before the loop (or anything else) starts.
     */
    public function test_a_poll_timeout_of_zero_throws_before_the_loop_starts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$pollTimeoutSeconds must be a finite, positive number');

        (new QueueWorker($this->app(), new InMemoryQueue()))->run(0);
    }

    /**
     * The stronger proof behind the message above: an invalid poll
     * timeout must reject before pop() is ever reached, not merely
     * before the loop happens to call it a first time in practice.
     * SequencedPopQueue's own pop() would throw a RuntimeException if it
     * were ever invoked here — this test never catches that type, only
     * the validation's own InvalidArgumentException, so a broken
     * validate-before-I/O ordering would surface as this test failing
     * with the wrong exception rather than silently passing.
     */
    public function test_run_never_calls_pop_when_the_poll_timeout_is_invalid(): void
    {
        $queue = new SequencedPopQueue([
            new RuntimeException('pop() must never be called for an invalid poll timeout.'),
        ]);
        $worker = new QueueWorker($this->app(), $queue);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$pollTimeoutSeconds must be a finite, positive number');

        try {
            $worker->run(pollTimeoutSeconds: 0);
        } finally {
            self::assertSame([], $queue->popCalls, 'pop() must never be reached when the poll timeout itself is invalid.');
        }
    }

    /**
     * run()'s own $pollTimeoutSeconds/$queues arguments must reach
     * QueueInterface::pop() unchanged on every call — proven
     * deterministically (no sleep, no signal) by having the one job
     * popped call stop() from inside its own handle(): the loop's next
     * `while` check then exits before a second pop() would ever happen,
     * so exactly one recorded call exists to inspect.
     */
    public function test_run_forwards_its_poll_timeout_and_queue_list_to_pop(): void
    {
        $app = $this->app();
        $recorder = $app->get(Recorder::class);

        $serialized = JobSerializer::serialize(new RecordingJob('stops the loop'));
        $job = new QueuedJob($serialized['class'], $serialized['args'], handle: 1, queue: 'default', attempts: 1, maxAttempts: null);

        $queue = new SequencedPopQueue([$job]);
        $worker = new QueueWorker($app, $queue);

        $recorder->onRecord = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run(pollTimeoutSeconds: 7, queues: ['high', 'default']);

        self::assertSame([[7, ['high', 'default']]], $queue->popCalls);
    }

    /**
     * The other half of the same proof: once a pop() with a finite
     * timeout returns and the job it carried calls stop(), the loop must
     * not call pop() again — deterministic, since SequencedPopQueue's
     * one real job is consumed on the very first call, with nothing left
     * in its outcomes for a second call to find.
     */
    public function test_run_stops_without_polling_again_once_stop_is_observed(): void
    {
        $app = $this->app();
        $recorder = $app->get(Recorder::class);

        $serialized = JobSerializer::serialize(new RecordingJob('the only job'));
        $job = new QueuedJob($serialized['class'], $serialized['args'], handle: 1, queue: 'default', attempts: 1, maxAttempts: null);

        $queue = new SequencedPopQueue([$job]);
        $worker = new QueueWorker($app, $queue);

        $recorder->onRecord = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run(pollTimeoutSeconds: 1);

        self::assertCount(1, $queue->popCalls, 'the loop must not poll again once stop() has been observed.');
        self::assertSame(['the only job'], $recorder->messages);
        self::assertTrue($worker->shouldStop());
    }

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

    /**
     * @return array{0: AppScope, 1: QueueEventLog}
     */
    private function appWithEventLog(): array
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingQueueEventListener::class);
        $log = new QueueEventLog();

        $app = $this->app(static function (AppScope $app) use ($registry, $log): void {
            $app->instance(EventListenerRegistry::class, $registry);
            $app->instance(QueueEventLog::class, $log);
        });

        return [$app, $log];
    }

    /**
     * @return array{0: AppScope, 1: RecordingLogger}
     */
    private function appWithThrowingListenerAndLogger(): array
    {
        $registry = new EventListenerRegistry();
        $registry->register(ThrowingQueueEventListener::class);
        $logger = new RecordingLogger();

        $app = $this->app(static function (AppScope $app) use ($registry, $logger): void {
            $app->instance(EventListenerRegistry::class, $registry);
            $app->instance(LoggerInterface::class, $logger);
        });

        return [$app, $logger];
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
     * A backend answers a settlement with StaleJobHandleException when
     * the delivery the handle names is over — settled through another
     * call, or reclaimed once its reservation expired and handed on.
     * Nothing this worker asked for was written, and another worker may
     * be running the same job, so the loss is reported at warning level
     * and the loop keeps serving: letting the exception escape
     * processNext() would stop the loop for every job behind this one,
     * the exact failure this class exists to prevent.
     */
    public function test_a_stale_release_does_not_crash_the_worker(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);
        $queue->releaseShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame([], $queue->released, 'the fixture recorded no release since it threw instead');
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->failed, 'a lost settlement is never retried down another path');

        $warnings = self::entriesAt($logger, 'warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('lost its delivery before release()', $warnings[0]['message']);
        self::assertSame('release', $warnings[0]['context']['settlement']);
        self::assertSame(
            ['class' => FailingJob::class, 'queue' => 'default', 'attempts' => 1],
            $warnings[0]['context']['job'],
        );
    }

    public function test_a_stale_ack_does_not_crash_the_worker(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('the job still ran'));
        $queue->ackShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame(['the job still ran'], $app->get(Recorder::class)->messages);
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->released, 'losing the ack never turns a succeeded job into a retry');
        self::assertSame([], $queue->failed);

        $warnings = self::entriesAt($logger, 'warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('lost its delivery before ack()', $warnings[0]['message']);
        self::assertSame('ack', $warnings[0]['context']['settlement']);
    }

    public function test_a_stale_fail_does_not_crash_the_worker(): void
    {
        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);
        $queue->failShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame([], $queue->failed);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->acked);

        $warnings = self::entriesAt($logger, 'warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('lost its delivery before fail()', $warnings[0]['message']);
        self::assertSame('fail', $warnings[0]['context']['settlement']);
    }

    public function test_a_stale_ack_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('first'));
        $queue->push(new RecordingJob('still runs'));
        $queue->ackShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);
        $worker->processNext();
        $worker->processNext();

        self::assertSame(['first', 'still runs'], $app->get(Recorder::class)->messages);
    }

    public function test_a_stale_fail_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('boom'), maxAttempts: 1);
        $queue->push(new RecordingJob('still runs'));
        $queue->failShouldThrowStale = true;

        $worker = new QueueWorker($app, $queue);
        $worker->processNext();
        $worker->processNext();

        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    public function test_a_stale_release_does_not_stop_the_worker_from_processing_the_next_one(): void
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

        $worker->run(pollTimeoutSeconds: 1);

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
        $worker->run(pollTimeoutSeconds: 1);

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

    public function test_a_successful_job_dispatches_job_succeeded(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'), queue: 'reports');

        (new QueueWorker($app, $queue))->processNext(queues: ['reports']);

        self::assertCount(1, $log->succeeded);
        self::assertSame(RecordingJob::class, $log->succeeded[0]->class);
        self::assertSame('reports', $log->succeeded[0]->queue);
        self::assertSame(1, $log->succeeded[0]->attempts);
        self::assertSame([], $log->released);
        self::assertSame([], $log->failedPermanently);
    }

    public function test_a_retried_job_dispatches_job_released(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $log->released);
        self::assertSame(FailingJob::class, $log->released[0]->class);
        self::assertSame('default', $log->released[0]->queue);
        self::assertSame(1, $log->released[0]->attempts);
        self::assertInstanceOf(RuntimeException::class, $log->released[0]->exception);
        self::assertSame('deliberate failure', $log->released[0]->exception->getMessage());
        self::assertSame([], $log->succeeded);
        self::assertSame([], $log->failedPermanently);
    }

    /**
     * Each of JobSucceeded/JobReleased/JobFailedPermanently asserts a
     * durable transition that a stale settlement did not make, so none
     * of them may fire; JobSettlementLost carries what did happen,
     * including the operation attempted and the backend's own rejection
     * of it.
     */
    public function test_a_stale_release_dispatches_job_settlement_lost_instead_of_job_released(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);
        $queue->releaseShouldThrowStale = true;

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([], $log->released);
        self::assertSame([], $log->succeeded);
        self::assertSame([], $log->failedPermanently);

        self::assertCount(1, $log->settlementLost);
        self::assertSame(FailingJob::class, $log->settlementLost[0]->class);
        self::assertSame('default', $log->settlementLost[0]->queue);
        self::assertSame(1, $log->settlementLost[0]->attempts);
        self::assertSame(JobSettlement::Release, $log->settlementLost[0]->operation);
        self::assertSame(JobSettlement::Release, $log->settlementLost[0]->stale->operation);
        self::assertInstanceOf(RuntimeException::class, $log->settlementLost[0]->failure);
        self::assertSame('deliberate failure', $log->settlementLost[0]->failure->getMessage());
    }

    public function test_a_stale_ack_dispatches_job_settlement_lost_instead_of_job_succeeded(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));
        $queue->ackShouldThrowStale = true;

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([], $log->succeeded);
        self::assertSame([], $log->released);
        self::assertSame([], $log->failedPermanently);

        self::assertCount(1, $log->settlementLost);
        self::assertSame(RecordingJob::class, $log->settlementLost[0]->class);
        self::assertSame(JobSettlement::Ack, $log->settlementLost[0]->operation);
        self::assertNull(
            $log->settlementLost[0]->failure,
            'handle() returned — losing the delivery is not the job failing',
        );
    }

    public function test_a_stale_fail_dispatches_job_settlement_lost_instead_of_job_failed_permanently(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);
        $queue->failShouldThrowStale = true;

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame([], $log->failedPermanently);
        self::assertSame([], $log->succeeded);
        self::assertSame([], $log->released);

        self::assertCount(1, $log->settlementLost);
        self::assertSame(JobSettlement::Fail, $log->settlementLost[0]->operation);
        self::assertInstanceOf(RuntimeException::class, $log->settlementLost[0]->failure);
        self::assertSame('deliberate failure', $log->settlementLost[0]->failure->getMessage());
    }

    /**
     * The job's span closes either way — an unclosed span is worse than
     * one carrying the wrong exception. Which exception it carries is
     * the distinction: a stale ack has no other failure to report, so
     * the lost settlement is the failure; a stale release/fail keeps the
     * job's own exception, which is what the span was opened to describe.
     */
    public function test_a_stale_ack_closes_telemetry_as_a_settlement_failure(): void
    {
        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));
        $queue->ackShouldThrowStale = true;

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertSame([['jobStarted', RecordingJob::class], ['jobFinished', 'ack']], $telemetry->calls);
        self::assertCount(1, $telemetry->jobFinishedFailures);
        self::assertInstanceOf(StaleJobHandleException::class, $telemetry->jobFinishedFailures[0]);
    }

    public function test_a_stale_release_keeps_the_job_failure_as_the_telemetry_failure(): void
    {
        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);
        $queue->releaseShouldThrowStale = true;

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertSame([['jobStarted', FailingJob::class], ['jobFinished', 'release']], $telemetry->calls);
        self::assertCount(1, $telemetry->jobFinishedFailures);
        self::assertInstanceOf(RuntimeException::class, $telemetry->jobFinishedFailures[0]);
        self::assertSame('deliberate failure', $telemetry->jobFinishedFailures[0]->getMessage());
    }

    public function test_a_stale_fail_keeps_the_job_failure_as_the_telemetry_failure(): void
    {
        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);
        $queue->failShouldThrowStale = true;

        (new QueueWorker($this->app(), $queue))->processNext();

        self::assertSame([['jobStarted', FailingJob::class], ['jobFinished', 'fail']], $telemetry->calls);
        self::assertCount(1, $telemetry->jobFinishedFailures);
        self::assertInstanceOf(RuntimeException::class, $telemetry->jobFinishedFailures[0]);
        self::assertSame('deliberate failure', $telemetry->jobFinishedFailures[0]->getMessage());
    }

    /**
     * A throwing listener on the lost-settlement path is contained the
     * same way every other lifecycle listener is: reported through the
     * logger, never escaping the worker.
     */
    public function test_a_throwing_job_settlement_lost_listener_does_not_escape_the_worker(): void
    {
        [$app, $logger] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));
        $queue->ackShouldThrowStale = true;

        self::assertTrue((new QueueWorker($app, $queue))->processNext());

        $errors = self::entriesAt($logger, 'error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('A JobSettlementLost listener failed', $errors[0]['message']);
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private static function entriesAt(RecordingLogger $logger, string $level): array
    {
        return array_values(array_filter($logger->entries, static fn (array $entry): bool => $entry['level'] === $level));
    }

    /**
     * processNext() runs Kinetis\Container\TransactionGuardHook::
     * registerIfAvailable() against every job's scope — but that hook is
     * only ever meaningful once Kinetis\Persistence\TransactionGuard
     * exists, and that class lives in the separate, optional
     * kinetis/persistence package, never installed for this suite. This
     * is the real, always-true "not installed" branch of the hook's own
     * class_exists() gate, proving a job still runs normally rather than
     * benefiting from it implicitly the way every other test in this
     * file already does. The "is installed" branch — a dangling
     * transaction actually rolled back on both normal completion and a
     * throw — is proven in kinetis/persistence's own test suite instead,
     * the one place both QueueWorker and TransactionGuard are
     * simultaneously available.
     */
    public function test_processes_a_job_normally_when_the_persistence_package_is_not_installed(): void
    {
        self::assertFalse(class_exists('Kinetis\Persistence\TransactionGuard'));

        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('still runs'));

        (new QueueWorker($app, $queue))->processNext();

        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
        self::assertCount(1, $queue->acked);
    }

    public function test_a_permanently_failed_job_dispatches_job_failed_permanently_with_redacted_args(): void
    {
        [$app, $log] = $this->appWithEventLog();
        $queue = new InMemoryQueue();
        $queue->push(new SensitiveFailingJob(4812, 'ana@example.com', 'not-a-real-token'), maxAttempts: 1);

        (new QueueWorker($app, $queue))->processNext();

        self::assertCount(1, $log->failedPermanently);
        self::assertSame(SensitiveFailingJob::class, $log->failedPermanently[0]->class);
        self::assertSame('default', $log->failedPermanently[0]->queue);
        self::assertSame(1, $log->failedPermanently[0]->attempts);
        self::assertInstanceOf(RuntimeException::class, $log->failedPermanently[0]->exception);
        self::assertSame(
            ['userId' => 4812, 'email' => '[redacted]', 'resetToken' => '[redacted]'],
            $log->failedPermanently[0]->args,
        );
        self::assertSame([], $log->succeeded);
        self::assertSame([], $log->released);
    }

    /**
     * A throwing lifecycle-event listener must never be able to reclassify
     * a job's already-durable outcome — the transition already happened
     * before the listener ran.
     */
    public function test_a_throwing_JobSucceeded_listener_leaves_exactly_one_ack_and_no_release_or_fail(): void
    {
        [$app, $logger] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'processNext() must not let the listener failure escape it');
        self::assertSame([1], $queue->acked);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed);

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        self::assertCount(1, $errors, 'the listener failure is still observable through a healthy logger');
        self::assertStringContainsString('JobSucceeded', $errors[0]['message']);
    }

    public function test_a_throwing_JobSucceeded_listener_does_not_prevent_a_following_job_from_running(): void
    {
        [$app, ] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('first'));
        $queue->push(new RecordingJob('second'));

        $worker = new QueueWorker($app, $queue);
        self::assertTrue($worker->processNext());
        self::assertTrue($worker->processNext());

        self::assertSame([1, 2], $queue->acked);
        self::assertSame(['first', 'second'], $app->get(Recorder::class)->messages);
    }

    public function test_a_throwing_JobReleased_listener_leaves_exactly_one_release_and_no_ack_or_fail(): void
    {
        [$app, $logger] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result);
        self::assertSame([1], $queue->released);
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->failed);

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        // One entry for the real job failure, one for the listener's own
        // failure — both are genuine anomalies, neither replaces the other.
        self::assertCount(2, $errors);
        self::assertTrue(
            array_any($errors, static fn (array $e): bool => str_contains($e['message'], 'JobReleased')),
            'the listener failure specifically is observable through the logger',
        );
    }

    public function test_a_throwing_JobReleased_listener_does_not_prevent_a_following_job_from_running(): void
    {
        [$app, ] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('first'), maxAttempts: 2);
        $queue->push(new FailingJob('second'), maxAttempts: 2);

        $worker = new QueueWorker($app, $queue);
        self::assertTrue($worker->processNext());
        self::assertTrue($worker->processNext());

        self::assertSame([1, 2], $queue->released);
    }

    public function test_a_throwing_JobFailedPermanently_listener_leaves_exactly_one_fail_and_no_ack_or_release(): void
    {
        [$app, $logger] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result);
        self::assertSame([1], $queue->failed);
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->released);

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        self::assertCount(2, $errors);
        self::assertTrue(
            array_any($errors, static fn (array $e): bool => str_contains($e['message'], 'JobFailedPermanently')),
        );
    }

    public function test_a_throwing_JobFailedPermanently_listener_does_not_prevent_a_following_job_from_running(): void
    {
        [$app, ] = $this->appWithThrowingListenerAndLogger();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('first'), maxAttempts: 1);
        $queue->push(new FailingJob('second'), maxAttempts: 1);

        $worker = new QueueWorker($app, $queue);
        self::assertTrue($worker->processNext());
        self::assertTrue($worker->processNext());

        self::assertSame([1, 2], $queue->failed);
    }

    /**
     * The durable-transition guarantee is not merely moved to the event
     * system — a throwing telemetry backend must be contained the exact
     * same way.
     */
    public function test_a_throwing_telemetry_backend_does_not_prevent_ack_from_succeeding(): void
    {
        $telemetry = new ThrowingTelemetry();
        $telemetry->throwOnJobFinished = true;
        Telemetry::global()->swap($telemetry);

        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result);
        self::assertSame([1], $queue->acked);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed);
    }

    /**
     * jobStarted() runs inside processNext()'s own try/finally, wrapped
     * in runBestEffort() — a throwing telemetry backend there must not
     * escape processNext(), leak the RequestScope the finally block
     * disposes, or leave the popped job with no transition at all. The
     * job still runs, gets its one correct transition, its scope is
     * disposed, and a following job still runs afterward.
     */
    public function test_a_throwing_telemetry_jobStarted_still_runs_the_job_transitions_it_and_disposes_the_scope(): void
    {
        $telemetry = new ThrowingTelemetry();
        $telemetry->throwOnJobStarted = true;
        Telemetry::global()->swap($telemetry);

        CapturedScopeHolder::$scope = null;

        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new ScopeCapturingViaStaticJob());
        $queue->push(new RecordingJob('second job still runs'));

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertSame([1], $queue->acked, 'the job ran and got its one correct transition despite jobStarted() throwing');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed(), 'the scope this job ran in was genuinely disposed, not leaked');

        self::assertTrue($worker->processNext());
        self::assertSame(['second job still runs'], $app->get(Recorder::class)->messages);
    }

    /**
     * JobSerializer::redact() already fails closed when the job's own
     * class no longer autoloads — but reflecting a class whose autoload
     * itself throws (a broken class file, a broken autoloader) is a
     * harder case redact() cannot detect in advance. Preparing the
     * failure log/event data must never block fail() from actually
     * running, and the fallback used when it does must genuinely be
     * fully redacted — not just present — in both the log entry and the
     * lifecycle event. A real, unforced throwing autoloader is used here
     * rather than mocking redact() itself, since PHP genuinely propagates
     * an autoloader's own exception out of class_exists()/new.
     */
    public function test_a_redact_failure_still_performs_the_fail_transition_with_a_fully_redacted_fallback(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingQueueEventListener::class);
        $log = new QueueEventLog();
        $logger = new RecordingLogger();

        $app = $this->app(static function (AppScope $app) use ($registry, $log, $logger): void {
            $app->instance(EventListenerRegistry::class, $registry);
            $app->instance(QueueEventLog::class, $log);
            $app->instance(LoggerInterface::class, $logger);
        });

        $unloadableClass = 'Kinetis\\Queue\\Tests\\Fixtures\\UnloadableJobForRedactTest';

        $autoloader = static function (string $class) use ($unloadableClass): void {
            if ($class === $unloadableClass) {
                throw new RuntimeException('Simulated autoload failure — the class file itself is broken.');
            }
        };
        spl_autoload_register($autoloader);

        try {
            $queuedJob = new QueuedJob(
                $unloadableClass,
                ['secret' => 'the original, never-to-be-logged value'],
                handle: 1,
                queue: 'default',
                attempts: 1,
                maxAttempts: 1,
            );
            $queue = new PresetQueuedJobQueue($queuedJob);

            $result = (new QueueWorker($app, $queue))->processNext();

            self::assertTrue($result);
            self::assertSame([1], $queue->failed, 'fail() still ran even though redact() itself threw while preparing the failure log/event');
            self::assertSame([], $queue->released);
            self::assertSame([], $queue->acked);

            self::assertCount(1, $log->failedPermanently);
            self::assertSame(['secret' => JobSerializer::REDACTED], $log->failedPermanently[0]->args, 'the JobFailedPermanently event carries the fully-redacted fallback, not the real value');

            $failureLogEntries = array_values(array_filter($logger->entries, static fn (array $e): bool => str_contains($e['message'], 'failed permanently')));
            self::assertCount(1, $failureLogEntries);
            self::assertSame(['secret' => JobSerializer::REDACTED], $failureLogEntries[0]['context']['job']['args'], 'the failure log entry carries the fully-redacted fallback too');

            $encoded = json_encode([$log->failedPermanently[0]->args, $failureLogEntries[0]['context']['job']], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('the original, never-to-be-logged value', $encoded, 'the real argument value leaks nowhere');
        } finally {
            spl_autoload_unregister($autoloader);
        }
    }

    /**
     * The real-failure log line runs contained, ahead of release()/
     * fail() — a broken logger there must not be able to block the
     * transition it's describing from actually running.
     */
    public function test_a_throwing_logger_reporting_the_real_failure_does_not_block_the_fail_transition(): void
    {
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, new ThrowingLogger()));
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 1);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'processNext() must not let the logger failure escape it');
        self::assertSame([1], $queue->failed);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->acked);
    }

    public function test_a_throwing_logger_reporting_the_real_failure_does_not_block_the_release_transition(): void
    {
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, new ThrowingLogger()));
        $queue = new InMemoryQueue();
        $queue->push(new FailingJob('deliberate failure'), maxAttempts: 2);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result);
        self::assertSame([1], $queue->released);
        self::assertSame([], $queue->failed);
        self::assertSame([], $queue->acked);
    }

    /**
     * runBestEffort()'s $report callback is itself run through
     * runBestEffort() with no further $report, specifically so a logger
     * that throws while reporting a post-transition observer's own
     * failure (here: telemetry, on top of an already-successful ack())
     * can't escape either. Proves the recursive self-protection actually
     * holds, not just that a single layer of logger failure is contained.
     */
    public function test_a_throwing_logger_reporting_a_post_transition_telemetry_failure_does_not_escape(): void
    {
        $telemetry = new ThrowingTelemetry();
        $telemetry->throwOnJobFinished = true;
        Telemetry::global()->swap($telemetry);

        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, new ThrowingLogger()));
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('hello'));
        $queue->push(new RecordingJob('second job still runs'));

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext(), 'neither the telemetry failure nor the logger failure reporting it escapes processNext()');
        self::assertSame([1], $queue->acked, 'the single correct transition still happened');
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed);

        self::assertTrue($worker->processNext());
        self::assertSame([1, 2], $queue->acked, 'a following job still runs afterward');
    }

    /**
     * By the time disposeScope() runs, ack() has already made the job's
     * outcome durable — a disposal failure on top of that must never
     * trigger a second transition, never escape processNext(), and must
     * still let RequestScope::dispose()'s own "every callback runs, even
     * after an earlier one throws" guarantee run to completion.
     */
    public function test_a_successful_jobs_disposal_failure_does_not_escape_processNext_and_is_logged(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new DisposalFailingJob());

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'a disposal failure must not escape processNext()');
        self::assertSame([1], $queue->acked, 'the job succeeded — ack() already ran before disposal');
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed);

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed(), 'the scope is still marked disposed even though a callback failed');

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('dispose callback failed', $errors[0]['message']);
        self::assertStringContainsString(DisposalFailingJob::class, $errors[0]['message'], 'the log identifies which job the disposal failure belongs to');
        self::assertSame(
            ['class' => DisposalFailingJob::class, 'queue' => 'default', 'attempts' => 1],
            $errors[0]['context']['job'],
        );
    }

    public function test_a_successful_jobs_disposal_failure_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new DisposalFailingJob());
        $queue->push(new RecordingJob('still runs'));

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertTrue($worker->processNext());
        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    /**
     * run()'s own persistent loop specifically — not just two direct
     * processNext() calls — must survive a disposal failure exactly the
     * same way it already survives a job failure (see
     * test_run_returns_once_stopped_and_finishes_the_job_in_flight).
     */
    public function test_run_processes_every_job_despite_a_disposal_failure_in_the_middle(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new DisposalFailingJob());
        $queue->push(new RecordingJob('after the disposal failure'));

        $worker = new QueueWorker($app, $queue);

        $app->get(Recorder::class)->onRecord = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run(pollTimeoutSeconds: 1);

        // Both jobs ran to completion: the first's disposal failure never
        // stopped run()'s loop from reaching the second, which is itself
        // what triggered the stop signal — the job in flight when stop()
        // is called still finishes, the same as
        // test_run_returns_once_stopped_and_finishes_the_job_in_flight.
        self::assertSame(['after the disposal failure'], $app->get(Recorder::class)->messages);
        self::assertSame([1, 2], $queue->acked);
    }

    /**
     * The dual-failure case: the job itself fails (so release()/fail()
     * is the real, already-durable transition) and disposal also fails.
     * The disposal failure must never trigger a second transition or
     * escape processNext() — it is logged separately, alongside the
     * job's own real failure, and the later dispose callback still runs.
     */
    public function test_a_failing_jobs_disposal_failure_does_not_escape_processNext_and_is_logged_alongside_the_real_failure(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new FailingJobWithFailingDisposal(), maxAttempts: 1);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'a disposal failure must not escape processNext()');
        self::assertSame([1], $queue->failed, 'the job\'s own failure exhausted its one attempt — fail() already ran before disposal');
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->acked);

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed());

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        // One entry for the job's own real failure, one for the
        // disposal failure — neither replaces the other.
        self::assertCount(2, $errors);
        self::assertTrue(
            array_any($errors, static fn (array $e): bool => str_contains($e['message'], 'the job itself failed')),
            'the job\'s own real failure is still logged, not replaced by the disposal failure',
        );
        self::assertTrue(
            array_any($errors, static fn (array $e): bool => str_contains($e['message'], 'dispose callback failed')),
            'the disposal failure is logged separately',
        );

        $disposalError = array_values(array_filter($errors, static fn (array $e): bool => str_contains($e['message'], 'dispose callback failed')))[0];
        self::assertStringContainsString(FailingJobWithFailingDisposal::class, $disposalError['message'], 'the log identifies which job the disposal failure belongs to');
        self::assertSame(
            ['class' => FailingJobWithFailingDisposal::class, 'queue' => 'default', 'attempts' => 1],
            $disposalError['context']['job'],
        );
    }

    public function test_a_failing_jobs_disposal_failure_does_not_stop_the_worker_from_processing_the_next_one(): void
    {
        $app = $this->app();
        $queue = new InMemoryQueue();
        $queue->push(new FailingJobWithFailingDisposal(), maxAttempts: 1);
        $queue->push(new RecordingJob('still runs'));

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext());
        self::assertTrue($worker->processNext());
        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    /**
     * processNext()'s finally block is also reached when handleSuccess()
     * itself throws — the backend's own ack() call failing — before the
     * durable transition ever completes, not only after. Disposal must
     * still be attempted and contained (never a second transition,
     * never allowed to replace the real, already-in-flight failure), but
     * the transition failure itself is the real outcome here and is left
     * to propagate exactly as it always would have — disposeScope()
     * only ever protects against a cleanup failure corrupting it, it
     * never contains the transition failure itself.
     */
    public function test_a_transition_failure_escapes_while_disposal_is_still_contained_and_logged(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new InMemoryQueue();
        $queue->push(new DisposalFailingJob());
        $queue->ackShouldThrow = true;

        $worker = new QueueWorker($app, $queue);

        $threw = null;

        try {
            $worker->processNext();
        } catch (RuntimeException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'the transition failure itself is the real, escaping outcome — processNext() does not swallow it');
        self::assertSame('ack() itself failed', $threw->getMessage());

        self::assertSame([], $queue->acked, 'ack() itself threw, so no transition was ever recorded as completed');
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed, 'disposal never triggers a transition of its own — no second attempt through any path');

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'disposal is still attempted, and every callback still runs, even with a different failure already in flight');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed());

        $errors = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'error'));
        self::assertCount(1, $errors, 'only the disposal failure is logged here — the transition failure propagates as the real exception rather than being logged and swallowed');
        self::assertStringContainsString('dispose callback failed', $errors[0]['message']);
        self::assertSame(
            ['class' => DisposalFailingJob::class, 'queue' => 'default', 'attempts' => 1],
            $errors[0]['context']['job'],
        );
    }

    /**
     * A durable backend's own pop() throws MalformedJobSettledException
     * once it has already permanently removed a reserved-but-malformed
     * message (see that exception's own docblock) — processNext() must
     * report the item as consumed (true), log it, and touch neither a
     * fresh RequestScope-scoped resource nor job telemetry, since there
     * was never a real job to run any of that for.
     */
    public function test_a_malformed_job_is_logged_and_reported_as_consumed_with_no_telemetry(): void
    {
        $logger = new RecordingLogger();
        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));
        $queue = new SequencedPopQueue([
            new MalformedJobSettledException('default', MalformedQueuedJobDataException::invalidJson('payload', '{not valid')),
        ]);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'a settled malformed message is reported the same way a real processed job is — something was consumed, not "nothing was there"');

        $warnings = array_values(array_filter($logger->entries, static fn (array $e): bool => $e['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('malformed job on queue "default"', $warnings[0]['message']);
        self::assertInstanceOf(MalformedQueuedJobDataException::class, $warnings[0]['context']['exception']);

        self::assertSame([], $telemetry->calls, 'no jobStarted()/jobFinished() call — there was never a real job for either to describe');
        self::assertSame([], $queue->acked);
        self::assertSame([], $queue->released);
        self::assertSame([], $queue->failed, 'the backend already settled the message itself before throwing — processNext() has no QueuedJob to call ack()/release()/fail() with here');
    }

    /**
     * The worker loop itself never sees the malformed-settle outcome as
     * anything but "one iteration done" — run() calls processNext()
     * again immediately, and a real job queued behind the malformed one
     * is picked up and processed normally.
     */
    public function test_the_worker_loop_continues_to_the_next_valid_job_after_a_malformed_one_is_settled(): void
    {
        $app = $this->app();
        $recorder = $app->get(Recorder::class);

        $serialized = JobSerializer::serialize(new RecordingJob('the real job still runs'));
        $realJob = new QueuedJob($serialized['class'], $serialized['args'], handle: 42, queue: 'default', attempts: 1, maxAttempts: null);

        $queue = new SequencedPopQueue([
            new MalformedJobSettledException('default', MalformedQueuedJobDataException::invalidJson('payload', '{not valid')),
            $realJob,
        ]);

        $worker = new QueueWorker($app, $queue);

        self::assertTrue($worker->processNext(), 'first iteration: the malformed message, settled');
        self::assertSame([], $recorder->messages, 'the real job has not run yet');

        self::assertTrue($worker->processNext(), 'second iteration: the real job behind it');
        self::assertSame(['the real job still runs'], $recorder->messages);
        self::assertSame([42], $queue->acked);
    }

    /**
     * "A throwing/missing logger cannot replace the outcome" — the
     * malformed-message outcome (settled, reported as consumed) is
     * identical whether logging succeeds or the logger itself throws;
     * the failing log call is contained the same way every other
     * best-effort observation in this class already is.
     */
    public function test_a_throwing_logger_does_not_prevent_the_malformed_job_outcome_from_being_reported(): void
    {
        $logger = new ThrowingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new SequencedPopQueue([
            new MalformedJobSettledException('default', MalformedQueuedJobDataException::invalidJson('payload', '{not valid')),
        ]);

        $result = (new QueueWorker($app, $queue))->processNext();

        self::assertTrue($result, 'the logger throwing must not turn a settled malformed message into a different, escaping outcome');
        self::assertNotEmpty($logger->entries, 'the attempt to log was made — it just failed');
    }

    /**
     * An ordinary transport/infrastructure failure from pop() — a
     * dropped connection, a backend genuinely unreachable — is a
     * different exception type entirely from
     * MalformedJobSettledException and is never caught by the
     * malformed-job containment; it propagates and stops the worker
     * exactly as it always has.
     */
    public function test_an_ordinary_pop_transport_failure_still_propagates_uncontained(): void
    {
        $app = $this->app();
        $queue = new SequencedPopQueue([
            new RuntimeException('connection refused'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('connection refused');

        (new QueueWorker($app, $queue))->processNext();
    }
}
