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
 *
 * $queue is validated the same way every backend's own push()/pop()
 * already validates a queue name — QueueContract::assertValidQueueName(),
 * run here rather than trusted at every later use site. ack()/release()/
 * fail() all read $job->queue to resolve backend storage the same way
 * push()/pop() do (a Redis key segment, a SQL WHERE clause, an AMQP
 * routing key, an SQS queue lookup), and QueuedJob's own constructor is
 * public — there is no other single point every one of those paths
 * passes through, since a caller (a test, a hand-rolled QueueInterface
 * fake) can construct one directly rather than only ever receiving one
 * back from a real pop(). Validating once, here, means every later use
 * of $job->queue can trust it's already a real, well-formed name — no
 * backend needs its own redundant check before touching ack()/release()/
 * fail()'s own storage.
 *
 * $attempts and $maxAttempts are validated the identical way, for the
 * identical reason: this constructor is the one point every backend's own
 * pop() decoder passes through on the way to a real instance, and the one
 * point a caller constructing one by hand (a test, a hand-rolled
 * QueueInterface fake) passes through too. Without this, a durable
 * backend's own corrupted or malformed stored data — an attempts count
 * below the 1-indexed floor, a negative maxAttempts a lossy decode step
 * failed to catch — could reach QueueWorker directly, where
 * `$queuedJob->attempts >= $maxAttempts` would misclassify a job's very
 * first real attempt as already exhausted. QueueContract::
 * assertValidAttempts()/assertValidMaxAttempts() are what a durable
 * backend's own pop() decoder should already have satisfied via
 * QueueContract::coerceStoredInteger() before ever reaching here — this
 * is the safety net that catches it regardless of whether that happened,
 * not the only place it's checked.
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
    ) {
        QueueContract::assertValidQueueName($queue);
        QueueContract::assertValidAttempts($attempts);
        QueueContract::assertValidMaxAttempts($maxAttempts);
    }
}
