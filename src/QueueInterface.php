<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * One contract, multiple backends — RedisQueue and SqlQueue both
 * implement this identically from a caller's perspective, the same
 * "define the contract once, let concrete backends vary" shape already
 * proven by Psr\SimpleCache\CacheInterface (RedisSimpleCache/
 * NullSimpleCache) and MigrationRepositoryInterface elsewhere in Kinetis.
 *
 * $queue on push() and $queues on pop() are both appended last, not
 * inserted earlier in the parameter list — the same "never break an
 * existing positional call" discipline CorsMiddleware's
 * allowedOriginPatterns and JwtAuthMiddleware's revocationStore already
 * follow. Priority is expressed by list *order* (`--queue=high,default`),
 * not a numeric per-job priority score: pop() must check $queues in the
 * given order and only move on to the next one once the current one has
 * nothing available.
 *
 * pop(int $timeoutSeconds) is a Fiber-suspending "block until something's
 * available, or this many seconds pass" contract every backend must
 * honor, not just whichever one has a native blocking primitive. Redis
 * satisfies it directly; SqlQueue has no equivalent SQL primitive and
 * implements the same contract via a poll loop suspended with
 * Kinetis\Async\Timer::delay() between attempts — the caller can't tell
 * which is happening underneath.
 *
 * $maxAttempts on push() is a per-job override; null defers to the
 * processing QueueWorker's own $defaultMaxAttempts. QueuedJob::$attempts
 * (see that class) is the attempt number the current pop() represents;
 * fail() gives up on a job for good once $attempts reaches the effective
 * cap, distinct from release() (retry) and ack() (succeeded).
 */
interface QueueInterface
{
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void;

    /**
     * @param list<string> $queues checked in the given order — the first
     *     one with something available wins, regardless of position in
     *     the list otherwise
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
     * Jobs waiting to be popped from $queue — the number a worker still
     * has ahead of it, which is what answers "is this queue backing up?".
     *
     * Excludes jobs currently reserved by a worker and jobs still inside
     * their push() delay, since neither is available to pop. Backends
     * whose native count is an estimate rather than an exact figure say
     * so in their own docblock; treat the result as a monitoring signal,
     * not a value to branch on.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Discards every job waiting on $queue, returning how many were
     * removed. Jobs a worker has already reserved are untouched — they
     * belong to that worker until it acks, releases, or fails them.
     *
     * Destructive and unrecoverable: there is no dead-letter copy to
     * restore from.
     */
    public function clear(string $queue = 'default'): int;
}
