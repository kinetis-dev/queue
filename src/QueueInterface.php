<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * One contract, multiple backends — RedisQueue, SqsQueue, SqlQueue and
 * RabbitMqQueue all implement this identically from a caller's
 * perspective.
 *
 * Only operations every backend delivers exactly as written live here.
 * Anything a backend can merely approximate belongs to a separate
 * capability interface — see ClearableQueueInterface, which owns
 * clear() for that reason.
 *
 * **Delivery is at-least-once.** A job's handle() may run more than once
 * for the same logical job: a worker that dies mid-job leaves the
 * reservation to expire and be handed on, and release() republishes.
 * Write handlers that tolerate it.
 *
 * **push() arguments are validated identically by every backend**, via
 * QueueContract::assertValidPushArguments(), before telemetry,
 * serialization or any I/O: $delaySeconds must be 0 or positive,
 * $maxAttempts null (defer to the processing QueueWorker's own
 * $defaultMaxAttempts) or non-negative, $queue a valid name.
 * Exception\InvalidQueueArgumentException otherwise. SyncQueue validates
 * the same way even though neither value has an effect there, so a
 * mistake never behaves differently in local development. SqsQueue
 * layers its own 900-second delay cap — SQS's real limit — on top.
 *
 * **pop()'s contract, honored identically by every backend:**
 *
 * - $timeoutSeconds: 0 blocks with no deadline until something is
 *   available; a positive value blocks up to that many seconds and then
 *   returns null; a negative value is rejected.
 * - $queues: checked in the given order on every sweep, the first with
 *   something available wins. An empty list returns null immediately.
 *   Every name must be valid and no name may repeat.
 * - Every queue gets an immediate, non-blocking check in priority order
 *   before any backend waits on one, so a job already waiting anywhere
 *   is found regardless of its position. Only then does a backend with a
 *   native blocking primitive (Redis, SQS) wait on the highest-priority
 *   queue, and one without (SQL, RabbitMQ) pace its next sweep with a
 *   suspending delay.
 * - $timeoutSeconds bounds how long a backend keeps looking, not when
 *   pop() returns. Every wait is itself bounded. SQL and RabbitMQ cut
 *   their pacing delay to exactly what is left of the deadline. Redis
 *   and SQS wait in whole seconds — the smallest unit BRPOPLPUSH and
 *   WaitTimeSeconds accept, where 0 means "block forever" and "do not
 *   block" respectively — so their wait can outlast the deadline, and
 *   each rechecks it the moment that wait comes back empty rather than
 *   starting another sweep.
 * - What no backend can bound is an operation already in flight: a
 *   reserve, a receive or a settlement runs to its own completion or its
 *   transport's own timeout. So pop() can return after the deadline;
 *   $timeoutSeconds is how long a backend keeps looking, not a
 *   wall-clock guarantee no client is in a position to make.
 * - Once a backend's probe finds a job it is returned immediately, with
 *   no re-check of higher-priority queues: every probe reserves
 *   atomically the instant it succeeds and no backend has a
 *   peek-without-reserving primitive to re-check from. A job arriving on
 *   a higher-priority queue mid-wait is picked up on the next sweep.
 *
 * pop() suspends the calling Fiber rather than blocking the event loop,
 * whichever shape the backend takes underneath.
 *
 * **ack(), release() and fail() settle one delivery, not one job.**
 * QueuedJob::$handle is the receipt naming that delivery; see that class
 * for when a backend answers with Exception\StaleJobHandleException
 * because the delivery is over.
 *
 * **A malformed reserved message never reaches a caller as a QueuedJob
 * and never crashes pop().** Every durable backend reserves before it
 * decodes, so a decode failure leaves a real reservation with nothing to
 * release it. Each routes its decode through
 * QueueContract::settleIfMalformed(), which settles the message
 * permanently and raises Exception\MalformedJobSettledException;
 * QueueWorker catches that specifically and moves on. An ordinary
 * transport failure is a different type and still stops the worker.
 */
interface QueueInterface
{
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void;

    /**
     * @param list<string> $queues checked in the given order — see this
     *     interface's own docblock for the priority/timeout contract
     */
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob;

    public function ack(QueuedJob $job): void;

    public function release(QueuedJob $job): void;

    /**
     * Permanently removes the job without retrying it — the same storage
     * effect as ack(), used instead of it when giving up after
     * QueuedJob::$attempts reaches $maxAttempts, so logs and
     * backend-specific metrics can tell "succeeded" from "gave up".
     */
    public function fail(QueuedJob $job): void;

    /**
     * Outstanding waiting work on $queue — what answers "is this queue
     * backing up?". Includes a job still inside its push() delay;
     * excludes a job a worker currently holds reserved, except where a
     * reservation has gone stale past its visibility timeout, which
     * counts as waiting again on the backends that track one.
     *
     * A backend whose native count is an estimate (SQS's
     * ApproximateNumberOfMessages) says so in its own docblock. Treat
     * the result as a monitoring signal, not a value to branch on.
     */
    public function size(string $queue = 'default'): int;
}
