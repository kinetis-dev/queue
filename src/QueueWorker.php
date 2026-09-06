<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use InvalidArgumentException;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Events\EventDispatcher;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSettlementLost;
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\StaleJobHandleException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives QueueInterface::pop()/ack()/release()/fail() in a loop — a
 * persistent-worker loop of the same shape as FrankenPhpAdapter's
 * request loop, consuming jobs instead of HTTP requests.
 *
 * One fresh RequestScope per job, via the same
 * AppScope::createRequestScope() a request gets, for the same reason: a
 * job's resolved dependencies must not leak into the next job this
 * process picks up. Each scope also runs
 * {@see TransactionGuardHook::registerIfAvailable()}, so a job that opens
 * a transaction and returns or throws without closing it does not leave
 * it open into whatever runs next on the same connection. The scope is
 * disposed and gc_collect_cycles() runs after every job — a queue worker
 * is a persistent process by definition.
 *
 * A job's handle() is invoked via JobInvoker, the same invocation
 * SyncQueue uses for its inline push().
 *
 * **Each popped delivery gets exactly one durable transition.** Only the
 * job itself — deserializeJob() plus invoke() — decides which:
 * ack() when it returns, fail() when it throws with QueuedJob::$attempts
 * at the effective cap, release() otherwise. Everything that merely
 * observes that outcome (telemetry, the lifecycle events, the log lines)
 * runs through runBestEffort(), so a throwing listener, a broken
 * telemetry backend or a failing logger can never block a transition,
 * cause a second one, or reclassify a success as a failure. The
 * real-failure log line runs ahead of the transition, describing the
 * outcome rather than deciding it, and is contained the same way.
 *
 * A throwing job does not stop or escape the loop — the same "one bad
 * unit of work must not crash a long-running process" reasoning behind
 * ExceptionHandlerMiddleware. Neither does a transition the backend
 * rejects as stale: the delivery is over, so no
 * JobSucceeded/JobReleased/JobFailedPermanently is dispatched and
 * Events\JobSettlementLost reports what actually happened. Every other
 * exception from ack()/release()/fail() propagates — a backend refusing
 * writes is not a settled job, and stopping is the correct answer.
 *
 * $defaultMaxAttempts is the cap for a job that set none at push() time;
 * a job's own push(maxAttempts: ...) always wins. It defaults to 0 (no
 * retries); queue:work reads it from QUEUE_MAX_ATTEMPTS.
 *
 * SIGTERM and SIGINT stop the loop after the job in flight finishes, so
 * a deploy never kills a worker mid-job and strands it in the backend's
 * reserved state. Process supervision itself is left to whatever runs
 * the worker; Docker, systemd and Kubernetes all send SIGTERM first.
 */
final class QueueWorker
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly AppScope $app,
        private readonly QueueInterface $queue,
        private readonly int $defaultMaxAttempts = 0,
    ) {
        self::assertValidDefaultMaxAttempts($defaultMaxAttempts);
    }

    /**
     * Exposed so queue:work can validate before printing its startup
     * lines, and so the invariant cannot drift between the two call
     * sites. 0 is the real "no retries" default; a negative count has no
     * meaning attempt counting could act on.
     */
    public static function assertValidDefaultMaxAttempts(int $defaultMaxAttempts): void
    {
        if ($defaultMaxAttempts < 0) {
            throw new InvalidArgumentException("\$defaultMaxAttempts must not be negative, got {$defaultMaxAttempts}.");
        }
    }

    /**
     * Runs until stopped, one job at a time. Returns only after a
     * shutdown signal (or a stop() call) and the job in flight then has
     * finished.
     *
     * @param list<string> $queues checked in priority order — see
     *     QueueInterface::pop()
     */
    public function run(int $pollTimeoutSeconds = 5, array $queues = ['default']): void
    {
        self::assertValidPollTimeout($pollTimeoutSeconds);

        $this->listenForShutdownSignals();

        while (!$this->shouldStop) {
            $this->processNext($pollTimeoutSeconds, $queues);
        }
    }

    /**
     * run() needs a finite, positive timeout even though pop() itself
     * documents 0 as "block with no deadline". Signal dispatch sets
     * $shouldStop without interrupting an in-flight call, so the loop can
     * only observe it once pop() returns; passing 0 through would trap
     * the worker inside pop() on an idle queue and force supervision to
     * escalate to SIGKILL. A positive value bounds that to one poll
     * interval. Exposed for the same pre-flight reason as
     * assertValidDefaultMaxAttempts().
     */
    public static function assertValidPollTimeout(int $pollTimeoutSeconds): void
    {
        if ($pollTimeoutSeconds < 1) {
            throw new InvalidArgumentException(
                "\$pollTimeoutSeconds must be a positive number of seconds so the worker can periodically regain "
                . "control to observe a shutdown signal — 0 would block pop() indefinitely on an idle queue. "
                . "Got {$pollTimeoutSeconds}.",
            );
        }
    }

    /**
     * Stops the loop before the next job is popped. Called by the signal
     * handlers, and directly by anything driving the worker itself.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Whether this process can stop gracefully at all. ext-pcntl is
     * CLI-only and absent from the official PHP images unless installed
     * (`docker-php-ext-install pcntl`); without it there is no way to
     * observe SIGTERM, so supervision can only kill the worker outright
     * and whatever job was in flight is left for the backend to reclaim.
     *
     * Callers that can report this to an operator should: a worker
     * silently lacking graceful shutdown looks identical to one that has
     * it, right up to the deploy that truncates a job.
     */
    public static function supportsGracefulShutdown(): bool
    {
        return \function_exists('pcntl_async_signals') && \function_exists('pcntl_signal');
    }

    /**
     * Async dispatch means a signal arriving mid-job sets the flag
     * without interrupting the job — the loop reads it once that job has
     * been settled, which is what makes the shutdown safe rather than
     * merely quick.
     */
    private function listenForShutdownSignals(): void
    {
        if (!self::supportsGracefulShutdown()) {
            return;
        }

        \pcntl_async_signals(true);

        foreach ([\SIGTERM, \SIGINT] as $signal) {
            \pcntl_signal($signal, function (): void {
                $this->stop();
            });
        }
    }

    /**
     * Processes at most one job, returning whether one was found —
     * exposed separately from run() so a test or a process-N-then-exit
     * script can drive exactly one iteration.
     *
     * $pollTimeoutSeconds is passed straight to pop() with no floor of
     * its own: a single call is not a loop a shutdown signal needs to
     * interrupt, so 0 stays available and means what pop() documents.
     *
     * @param list<string> $queues
     */
    public function processNext(int $pollTimeoutSeconds = 5, array $queues = ['default']): bool
    {
        try {
            $queuedJob = $this->queue->pop($pollTimeoutSeconds, $queues);
        } catch (MalformedJobSettledException $malformed) {
            // The backend has already removed the poison message. Logged
            // from AppScope rather than a fresh RequestScope: no job ran,
            // so there is nothing job-scoped to resolve and no lifecycle
            // event to fire. Reported as one queue item consumed — a
            // message was found and dealt with.
            $this->runBestEffort(fn (): mixed => $this->app->get(LoggerInterface::class)->warning(
                $malformed->getMessage(),
                ['exception' => $malformed->getPrevious()],
            ));

            return true;
        }

        if ($queuedJob === null) {
            return false;
        }

        $scope = $this->app->createRequestScope();
        TransactionGuardHook::registerIfAvailable($scope);
        $telemetry = Telemetry::global();

        try {
            // A failed start leaves $jobToken null, which every telemetry
            // backend's jobFinished() already treats as nothing to
            // finish. Contained like every other observer: a throwing
            // backend here would otherwise leak this scope and leave the
            // popped job with no transition at all.
            $jobToken = null;

            $this->runBestEffort(
                function () use ($telemetry, $queuedJob, &$jobToken): void {
                    $jobToken = $telemetry->jobStarted($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $queuedJob->metadata);
                },
                $scope,
                "Starting telemetry for job \"{$queuedJob->class}\" failed",
            );

            $failure = null;

            try {
                JobInvoker::invoke(JobSerializer::deserializeJob($queuedJob->class, $queuedJob->args), $scope);
            } catch (Throwable $e) {
                $failure = $e;
            }

            if ($failure === null) {
                if ($this->transition(JobSettlement::Ack, $queuedJob, null, $scope, $telemetry, $jobToken)) {
                    $this->dispatch(
                        new JobSucceeded($queuedJob->class, $queuedJob->queue, $queuedJob->attempts),
                        'JobSucceeded',
                        $queuedJob,
                        $scope,
                    );
                }
            } else {
                $this->handleFailure($queuedJob, $failure, $scope, $telemetry, $jobToken);
            }
        } finally {
            $this->disposeScope($scope, $queuedJob);
        }

        return true;
    }

    /**
     * Chooses fail() over release() once QueuedJob::$attempts has reached
     * the effective cap, and describes the failure before either runs so
     * a broken logger cannot block the transition.
     */
    private function handleFailure(QueuedJob $queuedJob, Throwable $e, RequestScope $scope, Telemetry $telemetry, mixed $jobToken): void
    {
        $exhausted = $queuedJob->attempts >= ($queuedJob->maxAttempts ?? $this->defaultMaxAttempts);

        $context = [
            'class' => $queuedJob->class,
            'queue' => $queuedJob->queue,
            'attempts' => $queuedJob->attempts,
        ];

        // A job that will be retried is still held by the backend with
        // its payload intact, so logging the arguments adds nothing
        // recoverable. They are the only surviving record once the job is
        // given up on, and are redacted there per #[Sensitive]. redact()
        // already fails closed when the class no longer autoloads;
        // reflecting one that does can still throw, and that must not
        // reach the transition below, so it falls back to the same
        // fully-redacted shape built without reflection.
        $redactedArgs = null;

        if ($exhausted) {
            try {
                $redactedArgs = JobSerializer::redact($queuedJob->class, $queuedJob->args);
            } catch (Throwable) {
                $redactedArgs = array_fill_keys(array_keys($queuedJob->args), JobSerializer::REDACTED);
            }

            $context['args'] = $redactedArgs;
        }

        $this->runBestEffort(fn (): mixed => $scope->get(LoggerInterface::class)->error(
            $exhausted
                ? "Job \"{$queuedJob->class}\" failed permanently after {$queuedJob->attempts} attempt(s): {$e->getMessage()}"
                : "Job \"{$queuedJob->class}\" failed (attempt {$queuedJob->attempts}): {$e->getMessage()}",
            ['exception' => $e, 'job' => $context],
        ));

        $operation = $exhausted ? JobSettlement::Fail : JobSettlement::Release;

        if (!$this->transition($operation, $queuedJob, $e, $scope, $telemetry, $jobToken)) {
            return;
        }

        $this->dispatch(
            $exhausted
                ? new JobFailedPermanently($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $e, $redactedArgs ?? [])
                : new JobReleased($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $e),
            $exhausted ? 'JobFailedPermanently' : 'JobReleased',
            $queuedJob,
            $scope,
        );
    }

    /**
     * The job's one durable transition, followed by the telemetry that
     * closes its span. Returns whether the backend actually settled it:
     * a transition rejected as stale wrote nothing, so no completion
     * event may follow it — reportSettlementLost() takes over instead.
     * Every other exception from ack()/release()/fail() propagates.
     */
    private function transition(
        JobSettlement $operation,
        QueuedJob $queuedJob,
        ?Throwable $failure,
        RequestScope $scope,
        Telemetry $telemetry,
        mixed $jobToken,
    ): bool {
        try {
            match ($operation) {
                JobSettlement::Ack => $this->queue->ack($queuedJob),
                JobSettlement::Release => $this->queue->release($queuedJob),
                JobSettlement::Fail => $this->queue->fail($queuedJob),
            };
        } catch (StaleJobHandleException $stale) {
            $this->reportSettlementLost($queuedJob, $operation, $stale, $failure, $scope, $telemetry, $jobToken);

            return false;
        }

        $this->recordFinished($telemetry, $jobToken, $operation, $failure, $scope);

        return true;
    }

    /**
     * The path a settlement takes when the backend rejects it as stale:
     * the delivery is over — settled through another call, or reclaimed
     * after its reservation expired — so nothing was written.
     *
     * The loop continues, because losing a delivery is a normal
     * consequence of at-least-once delivery rather than a reason to stop
     * serving every job behind it. No completion event is dispatched,
     * because each asserts a transition that did not happen;
     * JobSettlementLost says what did, and a warning says it to an
     * operator with no listener registered.
     *
     * Telemetry closes the span either way — an unclosed span is worse
     * than one carrying the wrong exception. $failure is the job's own
     * exception on the release/fail paths and null on the ack path, so a
     * stale ack closes carrying $stale while a stale release/fail keeps
     * the job's own exception as the span's failure. $operation is what
     * this worker attempted, taken from the call site rather than read
     * back off $stale, so the two accounts stay comparable.
     */
    private function reportSettlementLost(
        QueuedJob $queuedJob,
        JobSettlement $operation,
        StaleJobHandleException $stale,
        ?Throwable $failure,
        RequestScope $scope,
        Telemetry $telemetry,
        mixed $jobToken,
    ): void {
        $this->runBestEffort(fn (): mixed => $scope->get(LoggerInterface::class)->warning(
            "Job \"{$queuedJob->class}\" lost its delivery before {$operation->value}() could settle it: {$stale->getMessage()}",
            [
                'exception' => $stale,
                'job' => ['class' => $queuedJob->class, 'queue' => $queuedJob->queue, 'attempts' => $queuedJob->attempts],
                'settlement' => $operation->value,
            ],
        ));

        $this->recordFinished($telemetry, $jobToken, $operation, $failure ?? $stale, $scope);

        $this->dispatch(
            new JobSettlementLost($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $operation, $stale, $failure),
            'JobSettlementLost',
            $queuedJob,
            $scope,
        );
    }

    private function recordFinished(Telemetry $telemetry, mixed $jobToken, JobSettlement $operation, ?Throwable $failure, RequestScope $scope): void
    {
        $this->runBestEffort(
            static function () use ($telemetry, $jobToken, $operation, $failure): void {
                $telemetry->jobFinished($jobToken, $operation->value, $failure);
            },
            $scope,
            "Recording telemetry for a job settled with {$operation->value}() failed",
        );
    }

    private function dispatch(object $event, string $name, QueuedJob $queuedJob, RequestScope $scope): void
    {
        $this->runBestEffort(
            fn (): mixed => $scope->get(EventDispatcher::class)->dispatch($event),
            $scope,
            "A {$name} listener failed for job \"{$queuedJob->class}\"",
        );
    }

    /**
     * Reached after the transition on the ordinary paths, and while an
     * exception is still propagating if a transition itself failed. A
     * disposal failure must never escape and stop the loop, never trigger
     * a second transition (nothing here touches $this->queue), and never
     * replace an exception already in flight — this method never throws,
     * so a `finally` calling it cannot silently do that.
     *
     * The logger is resolved from AppScope, not $scope: $scope is already
     * disposed by the time this runs.
     */
    private function disposeScope(RequestScope $scope, QueuedJob $queuedJob): void
    {
        try {
            $scope->dispose();
        } catch (Throwable $disposeFailure) {
            $this->runBestEffort(
                fn (): mixed => $this->app->get(LoggerInterface::class)->error(
                    "Request scope disposal failed for job \"{$queuedJob->class}\" (queue: {$queuedJob->queue}, attempt: {$queuedJob->attempts}): {$disposeFailure->getMessage()}",
                    ['exception' => $disposeFailure, 'job' => ['class' => $queuedJob->class, 'queue' => $queuedJob->queue, 'attempts' => $queuedJob->attempts]],
                ),
            );
        } finally {
            gc_collect_cycles();
        }
    }

    /**
     * Runs $action, whose failure must never affect a job's
     * already-decided outcome. Any exception is caught and, when $scope
     * and $description are given, reported through the scope's logger —
     * itself run the same no-throw way, so a broken logger reporting an
     * observer's failure cannot escape either.
     *
     * @param callable(): mixed $action
     */
    private function runBestEffort(callable $action, ?RequestScope $scope = null, ?string $description = null): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            if ($scope === null || $description === null) {
                return;
            }

            $this->runBestEffort(static fn (): mixed => $scope->get(LoggerInterface::class)->error(
                "{$description}: {$e->getMessage()}",
                ['exception' => $e],
            ));
        }
    }
}
