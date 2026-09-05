<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Exception\InvalidDelaySecondsException;
use Kinetis\Queue\Exception\InvalidMaxAttemptsException;
use Kinetis\Queue\Exception\InvalidPopTimeoutException;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\SyncQueue;
use Kinetis\Queue\Tests\Fixtures\CapturedScopeHolder;
use Kinetis\Queue\Tests\Fixtures\DisposalCallbackHolder;
use Kinetis\Queue\Tests\Fixtures\DisposalFailingJob;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\FailingJobWithFailingDisposal;
use Kinetis\Queue\Tests\Fixtures\IdentityCapturingJob;
use Kinetis\Queue\Tests\Fixtures\PayloadJob;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\RecordingLogger;
use Kinetis\Queue\Tests\Fixtures\ScopeCapturingViaStaticJob;
use Kinetis\Queue\Tests\Fixtures\ThrowingTelemetry;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncQueueTest extends TestCase
{
    /**
     * Telemetry::global() is a real per-process singleton — any test
     * that swaps in a custom backend must restore a clean one afterward,
     * or a later, unrelated test would silently observe it.
     */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
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

    public function test_push_invokes_the_job_immediately(): void
    {
        $app = $this->app();
        $queue = new SyncQueue($app);

        $queue->push(new RecordingJob('ran synchronously'));

        self::assertSame(['ran synchronously'], $app->get(Recorder::class)->messages);
    }

    public function test_pop_always_returns_null(): void
    {
        $queue = new SyncQueue($this->app());

        self::assertNull($queue->pop());
        self::assertNull($queue->pop(5));
    }

    public function test_ack_and_release_are_no_ops(): void
    {
        $queue = new SyncQueue($this->app());
        $queuedJob = new QueuedJob(RecordingJob::class, ['message' => 'x'], handle: 1, queue: 'default');

        $queue->ack($queuedJob);
        $queue->release($queuedJob);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_failing_jobs_exception_propagates_to_the_caller(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deliberate failure');

        $queue->push(new FailingJob('deliberate failure'));
    }

    /**
     * push() reconstructs $job via JobSerializer before invoking it (see
     * the class docblock), so a scope captured onto the instance a test
     * holds a reference to would never be set — CapturedScopeHolder's
     * own docblock explains why a static handoff is what this needs
     * instead, the same fixture QueueWorkerTest already relies on for
     * the identical reason.
     */
    public function test_each_push_gets_its_own_fresh_scope(): void
    {
        $queue = new SyncQueue($this->app());

        CapturedScopeHolder::$scope = null;
        $queue->push(new ScopeCapturingViaStaticJob());
        $firstScope = CapturedScopeHolder::$scope;

        CapturedScopeHolder::$scope = null;
        $queue->push(new ScopeCapturingViaStaticJob());
        $secondScope = CapturedScopeHolder::$scope;

        self::assertNotNull($firstScope);
        self::assertNotNull($secondScope);
        self::assertNotSame($firstScope, $secondScope);
    }

    /**
     * push() runs Kinetis\Container\TransactionGuardHook::registerIfAvailable()
     * against each job's scope — but that hook is only ever meaningful
     * once Kinetis\Persistence\TransactionGuard exists, and that class
     * lives in the separate, optional kinetis/persistence package, never
     * installed for this suite. This is the real, always-true
     * "not installed" branch of the hook's own class_exists() gate,
     * proving a job still runs normally rather than benefiting from it
     * implicitly the way every other test in this file already does. The
     * "is installed" branch — a dangling transaction actually rolled
     * back — is proven in kinetis/persistence's own test suite instead,
     * the one place both SyncQueue and TransactionGuard are
     * simultaneously available.
     */
    public function test_pushes_a_job_normally_when_the_persistence_package_is_not_installed(): void
    {
        self::assertFalse(class_exists('Kinetis\Persistence\TransactionGuard'));

        $app = $this->app();
        $queue = new SyncQueue($app);

        $queue->push(new RecordingJob('still runs'));

        self::assertSame(['still runs'], $app->get(Recorder::class)->messages);
    }

    /**
     * SyncQueue holds nothing durable of its own — push() just runs the
     * job inline, so there is no external send a telemetry failure could
     * cause to be duplicated. What this does prove: push() itself must
     * not report failure, or invoke the job a second time, merely
     * because the jobPushEnded() telemetry call that follows a
     * successful invocation fails — the same catch-block shape a real
     * durable adapter's push() has, just without a real send behind it.
     * kinetis/queue-sql's own SqlQueuePushTelemetryTest is the
     * durable-adapter counterpart: a real INSERT, proven to run exactly
     * once against a recording SqlLink.
     */
    public function test_a_successful_push_is_not_reported_as_failed_or_invoked_twice_when_telemetry_fails_to_end(): void
    {
        $telemetry = new ThrowingTelemetry();
        $telemetry->throwOnJobPushEnded = true;
        Telemetry::global()->swap($telemetry);

        $app = $this->app();
        $queue = new SyncQueue($app);

        $queue->push(new RecordingJob('ran once'));

        self::assertSame(['ran once'], $app->get(Recorder::class)->messages, 'the job ran exactly once, not twice');
    }

    /**
     * Only cleanup fails: per SyncQueue's own documented contract, that
     * failure genuinely is the outcome — it propagates, and telemetry
     * reflects the real cleanup failure rather than a false success. A
     * later dispose callback still runs despite an earlier one throwing.
     */
    public function test_a_successful_jobs_disposal_failure_propagates_and_telemetry_reflects_it(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $queue = new SyncQueue($this->app());

        try {
            $queue->push(new DisposalFailingJob());
            self::fail('Expected the disposal failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('dispose callback failed', $e->getMessage());
        }

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed());

        self::assertCount(1, $telemetry->jobPushEndedFailures);
        self::assertInstanceOf(RuntimeException::class, $telemetry->jobPushEndedFailures[0]);
        self::assertSame('dispose callback failed', $telemetry->jobPushEndedFailures[0]->getMessage(), 'telemetry reflects the real cleanup failure, not a false success');
    }

    /**
     * The dual-failure case: both the job and its scope's own disposal
     * fail. push() must rethrow the job's exact exception — not the
     * disposal failure, which PHP's own finally semantics would
     * otherwise silently replace it with. The disposal failure is logged
     * separately, and telemetry reflects the real job failure.
     */
    public function test_both_the_job_and_disposal_failing_rethrows_the_exact_job_exception(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $logger = new RecordingLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));
        $queue = new SyncQueue($app);

        $jobException = null;

        try {
            $queue->push(new FailingJobWithFailingDisposal());
            self::fail('Expected the job\'s own exception to propagate.');
        } catch (RuntimeException $e) {
            $jobException = $e;
            self::assertSame('the job itself failed', $e->getMessage());
        }

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(CapturedScopeHolder::$scope);
        self::assertTrue(CapturedScopeHolder::$scope->isDisposed());

        self::assertCount(1, $telemetry->jobPushEndedFailures);
        self::assertSame($jobException, $telemetry->jobPushEndedFailures[0], 'telemetry reflects the exact job exception, not the disposal failure');

        self::assertCount(1, $logger->entries);
        self::assertSame('error', $logger->entries[0]['level']);
        self::assertSame('dispose callback failed', $logger->entries[0]['context']['message']);
        self::assertInstanceOf(RuntimeException::class, $logger->entries[0]['context']['exception']);
        self::assertNotSame($jobException, $logger->entries[0]['context']['exception'], 'the logged failure is the disposal failure, not the job\'s own exception');
    }

    /**
     * SafeLogger::log($this->app->get(LoggerInterface::class), ...) is
     * not actually safe on its own: PHP evaluates that get() call before
     * log() is ever entered, so a throwing LoggerInterface binding
     * escapes uncaught right at the point disposal tries to report its
     * own failure — replacing the job's real exception with the logger
     * factory's instead. This proves it doesn't.
     */
    public function test_the_jobs_exact_exception_survives_even_when_the_logger_itself_cannot_be_resolved(): void
    {
        CapturedScopeHolder::$scope = null;
        DisposalCallbackHolder::$secondRan = false;

        $app = $this->app(static function (AppScope $app): void {
            $app->bind(LoggerInterface::class, static fn (): LoggerInterface => throw new RuntimeException('logger factory failed'), shared: false);
        });
        $queue = new SyncQueue($app);

        try {
            $queue->push(new FailingJobWithFailingDisposal());
            self::fail('Expected the job\'s own exception to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('the job itself failed', $e->getMessage(), 'the job\'s own exact exception must survive even when reporting the disposal failure is itself impossible');
        }

        self::assertTrue(DisposalCallbackHolder::$secondRan, 'disposal is still fully attempted regardless of whether reporting its failure is even possible');
    }

    public function test_push_rejects_an_empty_queue_name_before_ever_invoking_the_job(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidQueueNameException::class);
        $queue->push(new RecordingJob('should never run'), queue: '');
    }

    /**
     * $delaySeconds/$maxAttempts have no effect on this backend (see the
     * class docblock), but must be validated the same way every durable
     * backend validates them — a caller's own mistake must not silently
     * behave differently in local development than it would in
     * production.
     */
    public function test_push_rejects_a_negative_delay_before_ever_invoking_the_job(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidDelaySecondsException::class);
        $queue->push(new RecordingJob('should never run'), delaySeconds: -1);
    }

    public function test_push_rejects_a_negative_max_attempts_before_ever_invoking_the_job(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidMaxAttemptsException::class);
        $queue->push(new RecordingJob('should never run'), maxAttempts: -1);
    }

    /**
     * QueueContract::assertValidPushArguments() must run before
     * Telemetry::global()->jobPushStarted() is ever called — proven
     * directly, not merely by reading push()'s own source: a telemetry
     * backend whose jobPushStarted() throws unconditionally would surface
     * *its own* RuntimeException instead of the real validation failure
     * if the ordering were ever reversed.
     */
    public function test_push_rejects_a_negative_delay_before_telemetry_is_ever_started(): void
    {
        $telemetry = new ThrowingTelemetry();
        $telemetry->throwOnJobPushStarted = true;
        Telemetry::global()->swap($telemetry);

        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidDelaySecondsException::class);
        $queue->push(new RecordingJob('should never run'), delaySeconds: -1);
    }

    public function test_pop_rejects_a_negative_timeout_the_same_as_every_other_backend(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidPopTimeoutException::class);
        $queue->pop(-1);
    }

    public function test_pop_rejects_a_duplicate_queue_name_the_same_as_every_other_backend(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidQueueNameException::class);
        $queue->pop(0, ['default', 'default']);
    }

    public function test_pop_still_returns_null_for_valid_arguments(): void
    {
        $queue = new SyncQueue($this->app());

        self::assertNull($queue->pop(5, ['high', 'default']));
    }

    public function test_size_rejects_an_empty_queue_name_the_same_as_every_other_backend(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidQueueNameException::class);
        $queue->size('');
    }

    public function test_clear_rejects_an_empty_queue_name_the_same_as_every_other_backend(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(InvalidQueueNameException::class);
        $queue->clear('');
    }

    public function test_size_still_returns_zero_for_a_valid_queue_name(): void
    {
        $queue = new SyncQueue($this->app());

        self::assertSame(0, $queue->size('default'));
    }

    /**
     * The class docblock's central production-parity claim, proven
     * directly: the object that runs is a distinct, reconstructed
     * instance, never the one the caller pushed.
     */
    public function test_push_never_invokes_the_original_job_instance(): void
    {
        IdentityCapturingJob::$ranWithId = null;

        $queue = new SyncQueue($this->app());
        $original = new IdentityCapturingJob();

        $queue->push($original);

        self::assertNotNull(IdentityCapturingJob::$ranWithId);
        self::assertNotSame(spl_object_id($original), IdentityCapturingJob::$ranWithId);
    }

    /**
     * A payload that can't survive the round trip to a real worker fails
     * here too, at push() time — matching every durable backend, closing
     * the exact gap that made SyncQueue's "useful for local development"
     * promise misleading before this: a job could silently work here and
     * only fail once actually deployed against Redis/SQL/SQS/RabbitMQ.
     */
    public function test_push_rejects_a_job_whose_payload_cannot_survive_the_round_trip(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(UnserializableJobException::class);
        $queue->push(new PayloadJob(static fn () => null));
    }

    public function test_a_rejected_payload_is_still_reported_to_telemetry_as_a_failed_push(): void
    {
        $telemetry = new ThrowingTelemetry();
        Telemetry::global()->swap($telemetry);

        $queue = new SyncQueue($this->app());

        try {
            $queue->push(new PayloadJob(static fn () => null));
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException) {
            // Expected — the assertion below is the actual point.
        }

        self::assertCount(1, $telemetry->jobPushEndedFailures);
        self::assertInstanceOf(UnserializableJobException::class, $telemetry->jobPushEndedFailures[0]);
    }

    public function test_the_backend_is_usable_through_the_clear_capability_type(): void
    {
        $queue = new SyncQueue($this->app());

        self::assertInstanceOf(ClearableQueueInterface::class, $queue);

        // Called through the capability type, not the concrete class: a
        // backend that stopped declaring ClearableQueueInterface fails
        // here as a TypeError instead of passing quietly. The queue-name
        // check still throws rather than returning this backend's own 0.
        $this->expectException(InvalidQueueNameException::class);
        self::clearThrough($queue, '');
    }

    /** Typed as the capability, which is the whole point of the test above. */
    private static function clearThrough(ClearableQueueInterface $queue, string $name): int
    {
        return $queue->clear($name);
    }
}
