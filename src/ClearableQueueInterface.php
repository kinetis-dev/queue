<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * Discarding every job waiting on a queue — a capability a backend has
 * only when it can do exactly that, and nothing wider.
 *
 * QueueInterface carries only what every backend implements identically;
 * clearing is not one of those. SyncQueue, RedisQueue, SqlQueue and
 * RabbitMqQueue declare this interface; Kinetis\QueueSqs\SqsQueue does
 * not, for the reasons its own docblock gives — so an SQS-backed queue
 * has no clear() to call, and the type says so at the call site rather
 * than a method quietly doing something wider than its name promises.
 *
 * Extends QueueInterface rather than sitting beside it: clearing is a
 * queue operation, so anything holding this holds a whole queue and can
 * push(), pop() and size() through the same instance. A consumer that
 * needs clearing names this type and gets it from the container; one
 * that holds only QueueInterface asks with an instanceof (see
 * Console\ClearCommand), the same shape
 * Kinetis\SimpleCache\AtomicCounterInterface takes against PSR-16.
 */
interface ClearableQueueInterface extends QueueInterface
{
    /**
     * Discards every job waiting on $queue, returning how many the
     * backend removed.
     *
     * "Waiting" means unreserved: delayed jobs included, since a job
     * inside its own push() delay is outstanding work no worker has
     * claimed. Every reservation is left alone, and that includes one
     * whose visibility timeout has already passed — QueueInterface::size()
     * counts such a reservation as waiting again and pop() may reclaim
     * it one delivery at a time, but a clear has no handover to make and
     * the worker holding it may simply be slow, with a settlement still
     * to come. A job pushed after this call returns is never affected.
     *
     * The return value is what this call removed, not a size() taken
     * alongside it: a queue accepts pushes throughout, so the two are
     * separate observations of a moving number and a caller must not
     * expect them to agree.
     *
     * $queue is validated exactly as every other queue name is, via
     * QueueContract::assertValidQueueName(), before any backend I/O.
     *
     * Destructive and unrecoverable: there is no dead-letter copy to
     * restore from.
     */
    public function clear(string $queue = 'default'): int;
}
