<?php

declare(strict_types=1);

namespace Kinetis\Queue\Events;

/**
 * Dispatched by QueueWorker::processNext() right after a job's handle()
 * returns without throwing and the backend has ack()'d it — one hook for
 * "any job finished" instead of instrumenting every job class's own
 * handle().
 *
 * $durationMs is the wall-clock time of the job's handler in this worker,
 * in milliseconds; it excludes pop, renewal stop, and settlement.
 */
final readonly class JobSucceeded
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public string $queue,
        public int $attempts,
        public float $durationMs,
    ) {}
}
