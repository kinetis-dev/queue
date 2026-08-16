<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\AppScope;
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
 * A throwing job does not stop the loop or escape it — the same
 * "one bad unit of work must not crash a long-running process" reasoning
 * already applied to ExceptionHandlerMiddleware and McpServer's top-level
 * catch. It's logged either way; whether it's released back onto the
 * queue (retried) or given up on via QueueInterface::fail() depends on
 * whether QueuedJob::$attempts has reached the effective cap — either
 * way the worker moves on to the next job immediately, the decision is
 * never itself a reason to stop.
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
    ) {}

    /**
     * Runs until stopped, one job at a time. Returns only after a
     * shutdown signal (or a stop() call) and the job in flight at that
     * point has finished.
     *
     * @param list<string> $queues checked in priority order — see
     *     QueueInterface::pop()
     */
    public function run(int $pollTimeoutSeconds = 5, array $queues = ['default']): void
    {
        $this->listenForShutdownSignals();

        while (!$this->shouldStop) {
            $this->processNext($pollTimeoutSeconds, $queues);
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
     * @param list<string> $queues
     */
    public function processNext(int $pollTimeoutSeconds = 5, array $queues = ['default']): bool
    {
        $queuedJob = $this->queue->pop($pollTimeoutSeconds, $queues);

        if ($queuedJob === null) {
            return false;
        }

        $scope = $this->app->createRequestScope();

        try {
            /** @var Job $job */
            $job = JobSerializer::deserialize($queuedJob->class, $queuedJob->args);
            JobInvoker::invoke($job, $scope);
            $this->queue->ack($queuedJob);
        } catch (Throwable $e) {
            $maxAttempts = $queuedJob->maxAttempts ?? $this->defaultMaxAttempts;
            $exhausted = $queuedJob->attempts >= $maxAttempts;

            $scope->get(LoggerInterface::class)->error(
                $exhausted
                    ? "Job \"{$queuedJob->class}\" failed permanently after {$queuedJob->attempts} attempt(s): {$e->getMessage()}"
                    : "Job \"{$queuedJob->class}\" failed (attempt {$queuedJob->attempts}): {$e->getMessage()}",
                [
                    'exception' => $e,
                    'job' => ['class' => $queuedJob->class, 'args' => $queuedJob->args],
                ],
            );

            if ($exhausted) {
                $this->queue->fail($queuedJob);
            } else {
                $this->queue->release($queuedJob);
            }
        } finally {
            $scope->dispose();
            gc_collect_cycles();
        }

        return true;
    }
}
