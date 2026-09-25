<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Container\AppScope;
use Kinetis\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Runs a job's handle() immediately, inline, in push() itself — no
 * separate worker process, useful for local development. pop() always
 * returns null (nothing is ever stored); ack()/release()/fail() are
 * no-ops, since QueueWorker only calls them after a non-null pop().
 *
 * No queue connection selector accepts it — there is nothing for a worker
 * process to do against a backend that never stores anything. Construct
 * and register it in your own bootstrap instead, typically gated on
 * APP_ENV.
 *
 * A fresh RequestScope per push(), via the same
 * AppScope::createRequestScope() QueueWorker uses — not the caller's
 * active scope. A queued job runs in a separate process with
 * no shared scope, so reusing the caller's would let a job depend on
 * request-scoped state that is reachable in development and absent in
 * production.
 *
 * Unlike QueueWorker, a failing job's exception is not caught: it
 * propagates to whatever called push(). Swallowing it protects a
 * long-running loop with other jobs behind it, which is not this case,
 * and seeing the real error immediately is the point of running jobs
 * synchronously.
 *
 * Every initializer registered through AppScope::onRequestScopeCreated()
 * runs on that scope too, so the cleanup one registers runs on disposal
 * even when the job throws.
 *
 * Disposal precedence: if both the job and its scope's disposal fail,
 * push() rethrows the job's own exception — PHP's `finally` semantics
 * would otherwise replace it with the disposal failure. That failure is
 * logged through SafeLogger (resolved from AppScope, since the scope is
 * already disposed) and otherwise discarded. If only disposal fails, it
 * is the outcome: it propagates, and telemetry reflects it rather than
 * a false success.
 *
 * push()'s $queue, $delaySeconds and $maxAttempts, and release()'s own
 * $delaySeconds, are accepted for interface compliance and have no
 * effect — there is nothing to partition, delay or retry. All four are
 * still validated through QueueContract::assertValidPushArguments() and
 * QueueContract::assertValidReleaseDelay(), the same checks every
 * durable backend makes: a class that exists to make development behave
 * like production would undermine itself by accepting values a durable
 * backend rejects.
 *
 * Clearing is supported and always reports 0: nothing is ever waiting.
 *
 * push() runs $job through JobSerializer::serialize() then
 * deserializeJob() before invoking it, exactly as a durable backend does
 * before storing and popping, and the reconstructed instance is what
 * runs. That is what makes "runs immediately" mean the same thing as
 * "runs on a real worker later": a constructor holding something that
 * cannot survive the round trip (a resource, a closure, an unsupported
 * object — see JobSerializer) fails here, at push() time.
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
                // Nothing else failed, so this is the outcome: propagate
                // it, with telemetry reflecting the real cleanup failure
                // rather than a false success.
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

    /**
     * $delaySeconds is validated and then has nothing to act on, the
     * same stance push()'s own arguments take: a mistake must not behave
     * differently in local development than it does in production.
     */
    #[\Override]
    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        QueueContract::assertValidReleaseDelay($delaySeconds);

        // No storage to make the job available in, delayed or otherwise:
        // QueueWorker only calls this after a non-null pop(), which never
        // happens here — see the class docblock.
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
