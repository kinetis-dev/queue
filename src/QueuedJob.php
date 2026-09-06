<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * A backend-agnostic handle to one dequeued job, returned by
 * QueueInterface::pop() and handed back to ack()/release()/fail()
 * unmodified. $handle is opaque to everything except the backend that
 * produced it — a serialized payload, a row plus the token of the
 * reservation it came from, an SQS receipt handle, an AMQP delivery.
 * QueueWorker only passes it back.
 *
 * **$handle is a delivery receipt: it identifies one delivery of a job,
 * not the logical job.** The same job body reaching a worker again —
 * after a release(), or after a reservation expired — is a different
 * delivery with a different $handle. That is what lets a backend settle
 * precisely: a handle naming a reservation it still holds settles it, a
 * handle naming a finished delivery settles nothing and raises
 * Exception\StaleJobHandleException. A backend that cannot tell the two
 * apart says which of its settlements are unfenced in its own docblock.
 *
 * $queue is required, not defaulted: every real QueuedJob came from a
 * named queue, and ack()/release()/fail() need it to find the right
 * storage (a Redis key prefix, a SQL column, an AMQP routing key).
 *
 * $attempts is the attempt number this pop() represents, 1-indexed.
 * $maxAttempts is whatever push() was given; null defers to
 * QueueWorker::$defaultMaxAttempts. Both default here only for direct
 * construction in a test — a real pop() always sets both.
 *
 * The constructor validates all three. It is the one point every
 * backend's decoder and every hand-built fake passes through, so
 * corrupted stored data — an attempts count below the 1-indexed floor, a
 * negative maxAttempts — cannot reach QueueWorker, where
 * `$attempts >= $maxAttempts` would misread a first attempt as already
 * exhausted. A durable backend's decoder should already have satisfied
 * these via QueueContract; this is the net that catches it either way.
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
         * Opaque string metadata stored with the job at push time — the
         * instrumentation propagation channel. Backends carry it
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
