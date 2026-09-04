<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * One contract, multiple backends — RedisQueue, SqsQueue, SqlQueue, and
 * RabbitMqQueue all implement this identically from a caller's
 * perspective, the same "define the contract once, let concrete
 * backends vary" shape already proven by Psr\SimpleCache\CacheInterface
 * (RedisSimpleCache/NullSimpleCache) and MigrationRepositoryInterface
 * elsewhere in Kinetis.
 *
 * Only operations every backend can deliver exactly as written live
 * here. Anything a backend can merely approximate belongs to a separate
 * capability interface the backends that do meet its contract declare —
 * see ClearableQueueInterface, which owns clear() for that reason, and
 * Kinetis\SimpleCache\AtomicCounterInterface for the same split against
 * PSR-16.
 *
 * $queue on push() and $queues on pop() are both appended last, not
 * inserted earlier in the parameter list — the same "never break an
 * existing positional call" discipline CorsMiddleware's
 * allowedOriginPatterns and JwtAuthMiddleware's revocationStore already
 * follow. Priority is expressed by list *order* (`--queue=high,default`),
 * not a numeric per-job priority score.
 *
 * **push()'s arguments are validated identically by every backend, via
 * QueueContract::assertValidPushArguments(), before telemetry,
 * serialization, scope creation, or any backend I/O:** $delaySeconds
 * must be 0 (push immediately) or positive (delay by that many seconds)
 * — Exception\InvalidDelaySecondsException otherwise. $maxAttempts must
 * be null (defer to the processing QueueWorker's own
 * $defaultMaxAttempts) or non-negative (0 or more — 0 meaning no
 * retries) — Exception\InvalidMaxAttemptsException otherwise. $queue
 * follows the same rule pop()'s own $queues does — see below.
 * SyncQueue validates the identical way even though neither value has
 * any effect there, so a caller's mistake never silently behaves
 * differently in local development than it would against a durable
 * backend. SqsQueue layers its own additional, narrower constraint on
 * top — a real 900-second upper bound, Amazon SQS's own hard limit on
 * DelaySeconds — checked only once the shared floor already passed.
 *

 * **pop()'s precise contract, honored identically by every backend:**
 *
 * - $timeoutSeconds: 0 blocks with no deadline at all, until something's
 *   available; a positive value blocks for up to that many seconds
 *   before returning null; a negative value is rejected outright
 *   (QueueContract::assertValidPopTimeout(),
 *   Exception\InvalidPopTimeoutException) rather than silently treated
 *   as either of the two meaningful cases.
 * - $queues: checked in the given order, on every sweep — the first one
 *   with something available wins, regardless of how far into the list
 *   it sits. An empty $queues list returns null immediately, with no
 *   check at all: the one deliberately unvalidated case, since it's a
 *   genuine "nothing to check" rather than malformed input. Every
 *   individual name must be non-empty, and no name may repeat in one
 *   list — QueueContract::assertValidQueueList(),
 *   Exception\InvalidQueueNameException.
 * - Every queue is given an immediate, non-blocking check, in priority
 *   order, before any backend is ever allowed to block waiting on one —
 *   a job already waiting anywhere is always found before that,
 *   regardless of which position it's in. Kinetis\Queue\Support\PopSweep
 *   is the shared implementation of this every backend but SqlQueue
 *   delegates to directly (SqlQueue's own single, priority-ordered SQL
 *   query already checks every queue as one atomic operation, which is
 *   strictly better than a per-queue loop would be — see that class's
 *   own docblock).
 * - Once nothing is found on that immediate sweep, a backend with a
 *   native blocking primitive (Redis, SQS) blocks for a bounded slice of
 *   real time per queue, capped by both a small per-queue limit and
 *   whatever remains of the overall deadline, before sweeping again — a
 *   real deadline is never overshot by more than that one bounded slice,
 *   not by an entire per-queue wait the way an unbounded per-queue block
 *   could. A backend with no blocking primitive at all (RabbitMQ) paces
 *   retries the same way, via a bounded sleep between sweeps instead.
 * - Not attempted, and disclosed rather than silently assumed: once a
 *   backend's own probe finds a job, it is returned immediately, with no
 *   attempt to re-check higher-priority queues first. Every backend's
 *   probe already reserves/commits the job atomically the instant it
 *   succeeds (Redis's move to a processing list, SQS's receive-triggered
 *   invisibility, RabbitMQ's basic.get, SqlQueue's own row-level lock) —
 *   there is no "peek without reserving" primitive on any backend to
 *   recheck from, so a job arriving on a higher-priority queue while
 *   this one's own backend was blocked on a lower one is picked up on
 *   the very next full sweep instead, not necessarily immediately.
 *
 * pop() itself is a Fiber-suspending call regardless of which of the
 * above shapes a given backend takes underneath — Redis/SQS satisfy it
 * directly via their own native blocking primitives; SqlQueue and
 * RabbitMqQueue have no SQL/AMQP equivalent and implement the same
 * suspending contract via a poll loop instead (Kinetis\Async\Timer::delay()/
 * Amp\delay() between attempts) — the caller can't tell which is
 * happening underneath.
 *
 * **ack(), release() and fail() settle one delivery, not one job.**
 * QueuedJob::$handle is the receipt naming that delivery — see that
 * class's own docblock for the contract, including when a backend
 * answers a settlement with Exception\StaleJobHandleException because
 * the delivery is over.
 *
 * $maxAttempts on push() is a per-job override; null defers to the
 * processing QueueWorker's own $defaultMaxAttempts. QueuedJob::$attempts
 * (see that class) is the attempt number the current pop() represents;
 * fail() gives up on a job for good once $attempts reaches the effective
 * cap, distinct from release() (retry) and ack() (succeeded).
 *
 * **A malformed reserved message never reaches a caller as a QueuedJob,
 * and never crashes pop() either.** Every durable backend reserves a
 * message from its own storage before it can be decoded — moved to
 * Redis's processing list, given a SQL `reserved_at`, made invisible by
 * SQS, held as an unacked AMQP delivery — so a decode failure at that
 * point (invalid JSON, a missing/wrong-shaped `class`/`args`/`metadata`
 * field, an out-of-range counter) leaves a real reservation with nothing
 * to release it. Rather than let that exception escape pop() and crash
 * the worker loop — stranding the message forever on a backend with no
 * reservation-reclaim mechanism, or replaying it forever on one that
 * has, since the same malformed data crashes every retry identically —
 * every backend routes its own decode step through
 * QueueContract::settleIfMalformed(): the message is settled
 * permanently, using that backend's own fail()-equivalent primitive,
 * before pop() throws Exception\MalformedJobSettledException instead of
 * letting the original decode failure escape. QueueWorker catches this
 * specifically (narrower than a blanket catch around pop() as a whole),
 * logs it, and moves on to the next job — an ordinary transport failure
 * (a dropped connection, a backend genuinely unreachable) is a different
 * exception type entirely and still propagates and stops the worker
 * exactly as it always has.
 */
interface QueueInterface
{
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void;

    /**
     * @param list<string> $queues checked in the given order — see this
     *     interface's own docblock for the full priority/timeout
     *     contract, and QueueContract for the validation every backend
     *     applies before touching any backend I/O
     */
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob;

    public function ack(QueuedJob $job): void;

    public function release(QueuedJob $job): void;

    /**
     * Permanently removes the job without retrying it — the same storage
     * effect as ack(), used instead of it specifically when giving up
     * after QueuedJob::$attempts reaches $maxAttempts, so a caller reading
     * logs or backend-specific metrics can tell "succeeded" apart from
     * "gave up" even where the underlying removal is identical.
     */
    public function fail(QueuedJob $job): void;

    /**
     * Total outstanding waiting work on $queue — the number a worker
     * still has ahead of it, which is what answers "is this queue
     * backing up?". Includes a job still inside its push() delay: it is
     * genuinely outstanding work even though no worker can pop it yet.
     * Excludes a job a worker currently has reserved — that one belongs
     * to whichever worker holds it, not to "waiting," and (where a
     * backend supports one — see $visibilityTimeoutSeconds on SqlQueue)
     * a reservation gone stale past its visibility timeout counts as
     * waiting again, the same rule pop() itself applies when deciding
     * what it may reclaim.
     *
     * Every backend implements this identically — Redis, SQS, SQL, and
     * RabbitMQ all count delayed jobs and exclude reserved/in-flight ones
     * the same way. Backends whose native count is an estimate rather
     * than an exact figure (SQS's own ApproximateNumberOfMessages*, most
     * notably) say so plainly in their own docblock rather than silently
     * changing this definition; treat the result as a monitoring signal
     * either way, not a value to branch on.
     */
    public function size(string $queue = 'default'): int;
}
