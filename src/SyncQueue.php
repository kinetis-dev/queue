<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\AppScope;

/**
 * Runs a job's handle() immediately, inline, in push() itself — no
 * separate worker process needed, useful for local development. pop()
 * always returns null (nothing is ever actually stored); ack()/release()
 * are no-ops, since QueueWorker only ever calls them after a non-null
 * pop(), which never happens here.
 *
 * Not selectable via bin/queue's QUEUE_CONNECTION — there's nothing for a
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
 * $queue is accepted for interface compliance but has no effect — there's
 * only one place a job can go here (immediately), so a queue name has
 * nothing to partition. $maxAttempts is accepted for the same reason and
 * has no effect either — there's no retry here to cap, a failing job's
 * exception propagates immediately instead.
 */
final readonly class SyncQueue implements QueueInterface
{
    public function __construct(
        private AppScope $app,
    ) {}

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $scope = $this->app->createRequestScope();

        try {
            JobInvoker::invoke($job, $scope);
        } finally {
            $scope->dispose();
        }
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
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
}
