<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * A backend-agnostic handle to one dequeued job, returned by
 * QueueInterface::pop() and handed back to ack()/release() unmodified.
 * $handle is deliberately untyped/opaque to everything except the backend
 * that produced it — a Redis backend might stash the job's own serialized
 * payload there (needed to remove it from a processing list), a SQL
 * backend its row's primary key. QueueWorker never inspects $handle
 * itself, only passes it back.
 *
 * $queue is required, not defaulted — every real QueuedJob genuinely came
 * from a specific named queue, there's no ambiguous case. ack()/release()
 * need it: both backends partition their own storage by queue name (a
 * Redis key prefix, a SQL column), so knowing which queue a job came from
 * is what lets ack()/release() find the right place to update.
 *
 * $attempts is the attempt number this pop() represents (1-indexed:
 * 1 on the first attempt, 2 after one release(), and so on). $maxAttempts
 * is whatever was passed to push(); null defers to the worker's own
 * QueueWorker::$defaultMaxAttempts. Both default here (1 and null) only
 * for direct test construction — a real pop() always sets both
 * explicitly.
 */
final readonly class QueuedJob
{
    /**
     * @param class-string<Job> $class
     * @param array<string, mixed> $args
     */
    public function __construct(
        public string $class,
        public array $args,
        public mixed $handle,
        public string $queue,
        public int $attempts = 1,
        public ?int $maxAttempts = null,
        /**
         * Opaque string metadata stored with the job at push time —
         * the instrumentation propagation channel. Backends carry it
         * verbatim; nothing in the queue layer interprets it.
         *
         * @var array<string, string>
         */
        public array $metadata = [],
    ) {}
}
