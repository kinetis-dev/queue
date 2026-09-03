<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use InvalidArgumentException;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Events\EventDispatcher;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\StaleJobHandleException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives QueueInterface::pop()/ack()/release() in a loop — a fourth kind
 * of persistent-worker loop, the same shape as FrankenPhpAdapter's own
 * request loop, just consuming jobs instead of HTTP requests. One fresh
 * RequestScope per job, via the identical AppScope::createRequestScope()
 * a request gets, for the identical reason: a job's resolved dependencies
 * must not leak into the next job this same worker process picks up
 * later. gc_collect_cycles() runs after every job unconditionally — a
 * queue worker is a persistent process by definition, unlike Kernel,
 * which serves both persistent and boot-and-die runtimes and only forces
 * collection for the former.
 *
 * A job's handle() method is invoked via JobInvoker (reflection, not
 * through Job itself — see that interface's docblock for why it declares
 * no methods), the same invocation SyncQueue uses for its own inline
 * push().
 *
 * Each job's RequestScope also runs
 * {@see TransactionGuardHook::registerIfAvailable()} — the same dispose
 * hook Kernel wires per HTTP request — so a job that opens a database
 * transaction (directly, or through a resolved TransactionGuard) and
 * returns or throws without closing it does not leave that transaction
 * open into whatever job this same pooled/native connection serves next.
 *
 * A throwing job does not stop the loop or escape it — the same
 * "one bad unit of work must not crash a long-running process" reasoning
 * already applied to ExceptionHandlerMiddleware and McpServer's top-level
 * catch. It's logged either way; whether it's released back onto the
 * queue (retried) or given up on via QueueInterface::fail() depends on
 * whether QueuedJob::$attempts has reached the effective cap — either
 * way the worker moves on to the next job immediately, the decision is
 * never itself a reason to stop.
 *
 * Only the job itself — JobSerializer::deserializeJob()/JobInvoker::invoke()
 * — decides the outcome, and each popped handle receives exactly one
 * durable transition (ack()/release()/fail()) based on it. Not every
 * containing call runs strictly *after* that transition: telemetry's own
 * jobStarted() necessarily runs before the job does, and on failure, the
 * real-failure log line runs ahead of release()/fail() too, describing
 * the outcome rather than deciding it. What matters isn't ordering but
 * containment — jobStarted() and the real-failure log line, before or
 * after the transition, run through runBestEffort(), so nothing in them
 * can ever block, delay, or replace the transition itself, and any
 * failure is reported through the logger. The JobSerializer::redact()
 * call preparing that log line's arguments is contained the same way in
 * spirit but not through runBestEffort() — it has its own dedicated
 * fail-closed try/catch, falling back to a fully-redacted argument map
 * on any reflection failure with no separate report of its own; the real
 * job failure is already what the surrounding log line exists to carry.
 * jobFinished() and the JobSucceeded/JobReleased/JobFailedPermanently
 * dispatch are strictly post-transition, and are the ones that could
 * otherwise trigger a second, contradictory transition —
 * a throwing listener or telemetry backend there is caught, reported
 * through the logger (itself run the same no-throw way, so a broken
 * logger reporting an observer's own failure can't escape either), and
 * never allowed to reclassify a success as a failure or escape
 * processNext() and stop the worker.
 *
 * $defaultMaxAttempts is the cap applied to a job that didn't set its own
 * at push() time (QueuedJob::$maxAttempts null) — a job's own
 * push(maxAttempts: ...) always wins. Non-nullable, defaulting to 0 (no
 * retries); the queue:work command reads its value from
 * QUEUE_MAX_ATTEMPTS.
 *
 * SIGTERM and SIGINT stop the loop after the job in flight finishes, so
 * a deploy or `docker compose restart` never kills a worker mid-job and
 * strands it in the backend's reserved/processing state. Kinetis leaves
 * process supervision itself to whatever already runs the worker —
 * Docker, systemd, Kubernetes — and every one of those sends SIGTERM
 * before SIGKILL, so cooperating with that signal is all the graceful
 * half of a restart needs.
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
     * The one place both this constructor and an external caller wanting
     * to validate before any side effect of its own (queue:work prints a
     * startup line and, when ext-pcntl is missing, a warning, both before
     * ever constructing a QueueWorker — see WorkCommand) route through,
     * so the invariant can never drift between the two call sites.
     *
     * 0 is the real, documented "no retries" default — a negative count
     * has no meaning a job's own attempt-counting could act on.
     */
    public static function assertValidDefaultMaxAttempts(int $defaultMaxAttempts): void
    {
        if ($defaultMaxAttempts < 0) {
            throw new InvalidArgumentException("\$defaultMaxAttempts must not be negative, got {$defaultMaxAttempts}.");
        }
    }

    /**
     * Runs until stopped, one job at a time. Returns only after a
     * shutdown signal (or a stop() call) and the job in flight at that
     * point has finished.
     *
     * $pollTimeoutSeconds must be at least 1 — see
     * assertValidPollTimeout()'s own docblock for why 0
     * (QueueInterface::pop()'s own "block with no deadline at all"
     * value) is rejected specifically here, even though it is a
     * genuinely valid pop() timeout on its own.
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
     * The same shared-validation shape as assertValidDefaultMaxAttempts()
     * — one place run() and an external pre-flight caller (queue:work,
     * via WorkCommand) both route through. Requires a finite, positive
     * value — 0 or negative are both rejected, for two different
     * reasons: QueueInterface::pop()'s own documented contract is that 0
     * means block with *no deadline at all*, until something's
     * available (see that interface's own docblock — this is
     * deliberately unchanged there, and genuinely useful for a one-shot
     * caller; see processNext()'s own docblock for why it stays
     * reachable there). run()'s loop is different: it must periodically
     * regain control between pop() calls specifically so it can observe
     * a shutdown signal (see listenForShutdownSignals() — async signal
     * dispatch sets $shouldStop without interrupting an in-flight call,
     * so the loop only ever re-checks it once the current pop() call
     * itself returns). Passing pop()'s own 0 straight through here would
     * mean pop() never returns at all on an idle queue, permanently
     * trapping the worker inside it — SIGTERM/SIGINT would set
     * $shouldStop but the loop could never reach the check, defeating
     * the exact graceful-shutdown guarantee this class exists to give,
     * and forcing whatever supervises the process to escalate to
     * SIGKILL instead. A finite positive value bounds how long that can
     * ever take to at most one poll interval.
     */
    public static function assertValidPollTimeout(int $pollTimeoutSeconds): void
    {
        if ($pollTimeoutSeconds < 1) {
            throw new InvalidArgumentException(
                "\$pollTimeoutSeconds must be a finite, positive number of seconds so the worker can periodically "
                . "regain control to observe a shutdown signal — 0 would block pop() indefinitely on an idle queue, "
                . "trapping the worker until a job arrives. Got {$pollTimeoutSeconds}.",
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
     * Whether this process can stop gracefully at all. ext-pcntl is a
     * CLI-only extension and absent from the official PHP Docker images
     * unless explicitly installed (`docker-php-ext-install pcntl`);
     * without it there is no way to observe SIGTERM, so supervision can
     * only kill the worker outright and whatever job was in flight is
     * left for the backend's reclaim mechanism.
     *
     * Callers that can report this to an operator should — a worker
     * silently lacking graceful shutdown looks identical to one that has
     * it, right up to the deploy that truncates a job.
     */
    public static function supportsGracefulShutdown(): bool
    {
        return \function_exists('pcntl_async_signals') && \function_exists('pcntl_signal');
    }

    /**
     * Async signal dispatch means a signal arriving mid-job sets the flag
     * without interrupting the job — the loop reads it once that job has
     * been acked or released, which is what makes the shutdown safe
     * rather than merely quick.
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
     * Processes at most one job, returning whether one was actually
     * found — exposed separately from run() so a test (or a
     * process-N-then-exit script) can drive exactly one iteration.
     *
     * Unlike run(), $pollTimeoutSeconds here is passed straight to
     * QueueInterface::pop() with no extra floor of its own — a single
     * call, not a loop a shutdown signal needs to interrupt, so 0 stays
     * available and means exactly what pop() itself documents it to
     * mean: an intentionally unbounded one-shot wait, blocking until a
     * job exists with no deadline at all. See assertValidPollTimeout()'s
     * own docblock for why run() requires a finite, positive value
     * instead.
     *
     * @param list<string> $queues
     */
    public function processNext(int $pollTimeoutSeconds = 5, array $queues = ['default']): bool
    {
        try {
            $queuedJob = $this->queue->pop($pollTimeoutSeconds, $queues);
        } catch (MalformedJobSettledException $malformed) {
            // The backend has already permanently removed the poison
            // message by the time this is thrown — see that exception's
            // own docblock. Logged best-effort, from AppScope directly
            // rather than a fresh RequestScope: there is no real
            // QueuedJob here to run job-scoped work for, so creating one
            // just to dispose of it immediately after would be pure
            // overhead, and job telemetry/lifecycle events are
            // deliberately not fired either — those describe a job that
            // actually ran, which this one never did. Reported as one
            // queue item consumed (true), the same signal a real
            // processed job gives — a malformed message was genuinely
            // found and dealt with, not "nothing was there."
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
            // Best-effort, same as every other telemetry/event call in
            // this class, and for the same reason: a throwing telemetry
            // backend must not itself escape processNext() before the
            // job has even run, which would leak this scope (dispose()
            // below would never be reached) and leave the popped job
            // with no transition at all. A failed start leaves $jobToken
            // null — a harmless sentinel a real backend's own
            // jobFinished() already treats as "nothing to finish" the
            // same way NullTelemetry's own hooks do.
            $jobToken = null;

            $this->runBestEffort(
                function () use ($telemetry, $queuedJob, &$jobToken): void {
                    $jobToken = $telemetry->jobStarted($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $queuedJob->metadata);
                },
                fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                    "Starting telemetry for job \"{$queuedJob->class}\" failed: {$notifyFailure->getMessage()}",
                    ['exception' => $notifyFailure],
                ),
            );

            // Only this decides success vs. failure — nothing downstream
            // (a transition, telemetry, a listener) ever gets a second
            // say in the outcome.
            $failure = null;

            try {
                $job = JobSerializer::deserializeJob($queuedJob->class, $queuedJob->args);
                JobInvoker::invoke($job, $scope);
            } catch (Throwable $e) {
                $failure = $e;
            }

            if ($failure === null) {
                $this->handleSuccess($queuedJob, $scope, $telemetry, $jobToken);
            } else {
                $this->handleFailure($queuedJob, $failure, $scope, $telemetry, $jobToken);
            }
        } finally {
            $this->disposeScope($scope, $queuedJob);
        }

        return true;
    }

    /**
     * Usually reached after the job's one durable transition
     * (ack()/release()/fail()) has already happened — handleSuccess()/
     * handleFailure() both complete before the finally block in
     * processNext() ever reaches here. But not always: this finally is
     * also reached if handleSuccess()/handleFailure() itself throws
     * before completing that transition — a backend's own ack()/
     * release()/fail() call failing, for one — in which case that
     * exception is still propagating when this runs and there is no
     * completed transition to speak of yet. Either way, a disposal
     * failure here must never escape processNext() and stop the whole
     * worker loop, must never trigger a second transition (nothing in
     * this method ever touches $this->queue), and must never replace
     * whatever exception is already in flight — this method itself
     * never throws, so a PHP `finally` calling it can't silently do
     * that regardless of which case applies. Logged through
     * runBestEffort() — the same contained, no-throw path this class
     * already uses for every other observer, and already safe against a
     * throwing LoggerInterface *resolution*, not just a throwing
     * logger, since the resolution happens inside the callback
     * runBestEffort() itself wraps — resolving the logger from AppScope,
     * not $scope: $scope is already disposed by the time this runs, so
     * it can no longer resolve one safely.
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
     * The one durable transition for a job that ran to completion —
     * ack() — followed by best-effort observation of that already-decided
     * outcome, never a chance to revise it.
     */
    private function handleSuccess(QueuedJob $queuedJob, RequestScope $scope, Telemetry $telemetry, mixed $jobToken): void
    {
        $this->queue->ack($queuedJob);

        $this->runBestEffort(
            static function () use ($telemetry, $jobToken): void {
                $telemetry->jobFinished($jobToken, 'ack', null);
            },
            fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                "Recording telemetry for a succeeded job failed: {$notifyFailure->getMessage()}",
                ['exception' => $notifyFailure],
            ),
        );

        $this->runBestEffort(
            fn (): mixed => $scope->get(EventDispatcher::class)->dispatch(
                new JobSucceeded($queuedJob->class, $queuedJob->queue, $queuedJob->attempts),
            ),
            fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                "A JobSucceeded listener failed for job \"{$queuedJob->class}\": {$notifyFailure->getMessage()}",
                ['exception' => $notifyFailure],
            ),
        );
    }

    /**
     * The one durable transition for a job that threw — fail() once
     * QueuedJob::$attempts has reached the effective cap, release()
     * otherwise. Preparing to describe that outcome — the real-failure
     * log line, and the JobSerializer::redact() call behind it — runs
     * contained ahead of the transition, not after it, so neither can
     * ever block fail()/release() from running; completion telemetry and
     * the lifecycle-event dispatch follow the transition instead, the
     * same shape handleSuccess() uses. $e, the job's own real exception,
     * is what telemetry and the lifecycle event always carry; an
     * observer's own failure is reported separately and never replaces
     * it.
     */
    private function handleFailure(QueuedJob $queuedJob, Throwable $e, RequestScope $scope, Telemetry $telemetry, mixed $jobToken): void
    {
        $maxAttempts = $queuedJob->maxAttempts ?? $this->defaultMaxAttempts;
        $exhausted = $queuedJob->attempts >= $maxAttempts;

        $job = [
            'class' => $queuedJob->class,
            'queue' => $queuedJob->queue,
            'attempts' => $queuedJob->attempts,
        ];

        // A job that will be retried is still held by the backend with
        // its payload intact, so logging the arguments adds nothing
        // recoverable. They are the only surviving record once the job
        // is given up on, and are redacted there per #[Sensitive]. Kept
        // as its own variable, not read back out of $job below, so
        // there's no array key whose presence depends on $exhausted
        // for the JobFailedPermanently dispatch to get wrong.
        //
        // Preparing this — not deciding the outcome, only describing it
        // for logging/the lifecycle event — must never block fail()
        // below: redact() already fails closed when $queuedJob->class no
        // longer autoloads, but reflecting an autoloadable class can
        // still throw for other reasons, and that failure must not
        // propagate out of here before fail() has run. Falls back to the
        // same fully-redacted shape redact() itself produces for its own
        // fail-closed case, built with no reflection of its own so it
        // can't fail the same way.
        $redactedArgs = null;

        if ($exhausted) {
            try {
                $redactedArgs = JobSerializer::redact($queuedJob->class, $queuedJob->args);
            } catch (Throwable) {
                $redactedArgs = array_fill_keys(array_keys($queuedJob->args), JobSerializer::REDACTED);
            }
        }

        if ($redactedArgs !== null) {
            $job['args'] = $redactedArgs;
        }

        // Logging the real failure is itself best-effort, run ahead of
        // the transition below: a broken logger must not be able to
        // block ack()/release()/fail() from ever running at all.
        $this->runBestEffort(fn (): mixed => $scope->get(LoggerInterface::class)->error(
            $exhausted
                ? "Job \"{$queuedJob->class}\" failed permanently after {$queuedJob->attempts} attempt(s): {$e->getMessage()}"
                : "Job \"{$queuedJob->class}\" failed (attempt {$queuedJob->attempts}): {$e->getMessage()}",
            ['exception' => $e, 'job' => $job],
        ));

        if ($exhausted) {
            $this->queue->fail($queuedJob);

            $this->runBestEffort(
                static function () use ($telemetry, $jobToken, $e): void {
                    $telemetry->jobFinished($jobToken, 'fail', $e);
                },
                fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                    "Recording telemetry for a permanently failed job failed: {$notifyFailure->getMessage()}",
                    ['exception' => $notifyFailure],
                ),
            );

            /** @var array<string, mixed> $redactedArgs guaranteed non-null: $exhausted is true here */
            $this->runBestEffort(
                fn (): mixed => $scope->get(EventDispatcher::class)->dispatch(
                    new JobFailedPermanently($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $e, $redactedArgs),
                ),
                fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                    "A JobFailedPermanently listener failed for job \"{$queuedJob->class}\": {$notifyFailure->getMessage()}",
                    ['exception' => $notifyFailure],
                ),
            );

            return;
        }

        $releasedNormally = true;

        try {
            $this->queue->release($queuedJob);
        } catch (StaleJobHandleException) {
            // Backend-specific (currently only RedisQueue), but a
            // benign outcome regardless of which backend threw it: the
            // transition this call wanted has already happened through
            // another path — a duplicate release() call, or a retry
            // after a connection drop whose server-side outcome wasn't
            // known at the time it was made — so there's nothing left
            // to do. Caught here specifically so it can't crash the
            // worker the way an unhandled Throwable otherwise would,
            // defeating the very "one bad job must not stop the loop"
            // guarantee this class exists to give every other job. No
            // JobReleased dispatch either: this call made no actual
            // change, so there's nothing genuine to report.
            $releasedNormally = false;

            $this->runBestEffort(fn (): mixed => $scope->get(LoggerInterface::class)->info(
                "Job \"{$queuedJob->class}\" was already released through another call; nothing more to do.",
            ));
        }

        if ($releasedNormally) {
            $this->runBestEffort(
                fn (): mixed => $scope->get(EventDispatcher::class)->dispatch(
                    new JobReleased($queuedJob->class, $queuedJob->queue, $queuedJob->attempts, $e),
                ),
                fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                    "A JobReleased listener failed for job \"{$queuedJob->class}\": {$notifyFailure->getMessage()}",
                    ['exception' => $notifyFailure],
                ),
            );
        }

        $this->runBestEffort(
            static function () use ($telemetry, $jobToken, $e): void {
                $telemetry->jobFinished($jobToken, 'release', $e);
            },
            fn (Throwable $notifyFailure) => $scope->get(LoggerInterface::class)->error(
                "Recording telemetry for a released job failed: {$notifyFailure->getMessage()}",
                ['exception' => $notifyFailure],
            ),
        );
    }

    /**
     * Runs $action, whose own failure must never affect a job's
     * already-decided durable outcome. Any exception is caught and, when
     * $report is given, reported through it — itself run the same
     * no-throw way, so a broken logger reporting an observer's failure
     * can't itself escape and look like a second job failure. Never
     * rethrows, regardless of how many layers fail.
     *
     * @param callable(): mixed $action
     * @param (callable(Throwable): mixed)|null $report
     */
    private function runBestEffort(callable $action, ?callable $report = null): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            if ($report !== null) {
                $this->runBestEffort(static fn (): mixed => $report($e));
            }
        }
    }
}
