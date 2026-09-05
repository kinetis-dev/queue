<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Container\AppScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Runs a job's handle() immediately, inline, in push() itself — no
 * separate worker process needed, useful for local development. pop()
 * always returns null (nothing is ever actually stored); ack()/release()
 * are no-ops, since QueueWorker only ever calls them after a non-null
 * pop(), which never happens here.
 *
 * Not selectable via QUEUE_CONNECTION — there's nothing for a
 * worker process to do against a backend that never stores anything.
 * Construct and register it directly in your own application bootstrap
 * instead, typically gated on APP_ENV.
 *
 * A fresh RequestScope per push() call, via the identical
 * AppScope::createRequestScope() QueueWorker uses for each job — not the
 * caller's own currently-active scope. Deliberate: a job genuinely queued
 * runs in a completely separate worker process later, with no shared
 * scope at all, so reusing the caller's own scope here would let a job
 * accidentally depend on request-scoped state that happens to be
 * reachable in development but would silently be absent in production.
 *
 * Unlike QueueWorker, a failing job's exception is not caught here — it
 * propagates to whatever called push(). QueueWorker swallows a job's
 * exception so one failure can't crash a long-running process handling
 * others behind it; that reasoning doesn't apply to a single inline call
 * with nothing queued behind it, and seeing the real error immediately is
 * the actual point of running jobs synchronously during development.
 *
 * Disposal precedence: if both the job and the scope's own disposal fail,
 * push() rethrows the job's exact exception — PHP's own `finally`
 * semantics would otherwise silently replace it with the disposal
 * failure, which is exactly the defect this class avoids. The disposal
 * failure is logged separately, best-effort, through SafeLogger (the
 * scope is already disposed by then, so the logger is resolved from
 * AppScope, not the scope itself) and otherwise discarded. If only
 * disposal fails, that failure genuinely is the outcome: it propagates
 * normally, and telemetry reflects it rather than a false success.
 *
 * The scope still runs {@see TransactionGuardHook::registerIfAvailable()}
 * before invoking the job, the same as QueueWorker's own per-job scope —
 * a job that opens a transaction and throws before closing it gets
 * rolled back when this scope disposes, even though the exception is
 * about to propagate rather than being swallowed.
 *
 * $queue is accepted for interface compliance but has no effect — there's
 * only one place a job can go here (immediately), so a queue name has
 * nothing to partition. $delaySeconds/$maxAttempts are accepted for the
 * same reason and have no effect either — there's nothing to delay and no
 * retry here to cap, a failing job's exception propagates immediately
 * instead. All three are still validated via
 * QueueContract::assertValidPushArguments(), the identical check every
 * durable backend's own push() makes: a negative $delaySeconds/
 * $maxAttempts is a caller mistake regardless of which backend receives
 * it, and this class existing specifically to make local development
 * behave like production would be undermined if the one backend a
 * developer runs locally silently accepted a value every durable backend
 * rejects.
 *
 * Clearing is supported (ClearableQueueInterface) and always reports 0:
 * nothing is ever stored, so nothing is ever waiting to discard.
 *
 * push() runs $job through JobSerializer::serialize() then
 * deserializeJob() before ever invoking it, exactly like every durable
 * backend does before storing/popping a payload — the reconstructed
 * instance is what actually runs, never the caller's own $job. This is
 * the one thing that makes "runs immediately, useful for local
 * development" mean the same thing as "runs on a real worker later":
 * a job whose constructor holds something that can't survive that round
 * trip (a resource, a closure, an unsupported object — see
 * Kinetis\Queue\Support\WireValue) fails here too, at push() time,
 * instead of silently working in development and only failing once
 * actually deployed against a durable backend.
 */
final readonly class SyncQueue implements ClearableQueueInterface
{
    public function __construct(
        private AppScope $app,
    ) {}

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        QueueContract::assertValidPushArguments($delaySeconds, $queue, $maxAttempts);

        $telemetryToken = Telemetry::global()->jobPushStarted($job::class, $queue);

        // Mirrors every durable backend: a payload that can't survive
        // the round trip fails right here, before any scope exists —
        // see the class docblock. $job::class === $job::class always
        // holds for the reconstructed instance, so nothing below needs
        // to distinguish which one produced a given log/telemetry line.
        try {
            $serialized = JobSerializer::serialize($job);
            $job = JobSerializer::deserializeJob($serialized['class'], $serialized['args']);
        } catch (Throwable $e) {
            Telemetry::global()->jobPushEnded($telemetryToken, $e);

            throw $e;
        }

        $scope = $this->app->createRequestScope();
        TransactionGuardHook::registerIfAvailable($scope);

        $jobFailure = null;

        try {
            JobInvoker::invoke($job, $scope);
        } catch (Throwable $e) {
            $jobFailure = $e;
        }

        try {
            $scope->dispose();
        } catch (Throwable $disposeFailure) {
            if ($jobFailure === null) {
                // Nothing else failed — this genuinely is the outcome:
                // propagate it, with telemetry reflecting the real
                // cleanup failure rather than a false success.
                Telemetry::global()->jobPushEnded($telemetryToken, $disposeFailure);

                throw $disposeFailure;
            }

            // The job's own failure is already the real outcome about to
            // be rethrown below — see the class docblock for why a
            // cleanup failure on top of it must never replace it.
            // logFrom(), not log(): a throwing LoggerInterface binding/
            // factory must be contained too, not just the resolved
            // logger's own log() call — see SafeLogger::logFrom()'s own
            // docblock.
            SafeLogger::logFrom(
                fn (): LoggerInterface => $this->app->get(LoggerInterface::class),
                LogLevel::ERROR,
                "Request scope disposal failed while running queue job {job} synchronously, after the job's own failure was already the outcome: {message}",
                ['job' => $job::class, 'message' => $disposeFailure->getMessage(), 'exception' => $disposeFailure],
            );
        }

        Telemetry::global()->jobPushEnded($telemetryToken, $jobFailure);

        if ($jobFailure !== null) {
            throw $jobFailure;
        }
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        QueueContract::assertValidPopArguments($timeoutSeconds, $queues);

        return null;
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        // No-op: QueueWorker only calls this after a non-null pop(), which
        // never happens here — see the class docblock.
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        // No-op: QueueWorker only calls this after a non-null pop(), which
        // never happens here — see the class docblock.
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        // No-op: QueueWorker only calls this after a non-null pop(), which
        // never happens here — see the class docblock.
    }

    /**
     * Always empty: push() runs the job inline, so nothing is ever
     * waiting.
     */
    #[\Override]
    public function size(string $queue = 'default'): int
    {
        QueueContract::assertValidQueueName($queue);

        return 0;
    }

    /**
     * Nothing is ever waiting here, so there is never anything to
     * discard — 0 is the exact count ClearableQueueInterface asks for,
     * not a stand-in for an operation this backend cannot perform.
     */
    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        QueueContract::assertValidQueueName($queue);

        return 0;
    }
}
