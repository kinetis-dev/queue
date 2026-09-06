<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * Discarding every job waiting on a queue — a capability a backend has
 * only when it can do exactly that and nothing wider.
 *
 * SyncQueue, RedisQueue, SqlQueue and RabbitMqQueue declare this;
 * Kinetis\QueueSqs\SqsQueue does not, for the reasons its own docblock
 * gives, so the type says so at the call site rather than a method
 * quietly doing something wider than its name promises.
 *
 * Extends QueueInterface rather than sitting beside it: anything holding
 * this holds a whole queue. A consumer that needs clearing names this
 * type; one holding only QueueInterface asks with an instanceof (see
 * Console\ClearCommand).
 */
interface ClearableQueueInterface extends QueueInterface
{
    /**
     * Discards every job waiting on $queue, returning how many the
     * backend removed.
     *
     * "Waiting" means unreserved, delayed jobs included. Every
     * reservation is left alone, including one whose visibility timeout
     * has passed: a clear has no handover to make and the worker holding
     * it may simply be slow. A job pushed after this call returns is
     * never affected.
     *
     * The return value is what this call removed, not a size() taken
     * alongside it — a queue accepts pushes throughout, so the two are
     * separate observations of a moving number.
     *
     * Destructive and unrecoverable: there is no dead-letter copy.
     */
    public function clear(string $queue = 'default'): int;
}
