<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Instrumentation\Telemetry;
use Amp\Redis\Command\Boundary\ScoreBoundary;
use Amp\Redis\RedisClient;
use Throwable;

/**
 * A naive Redis list pop already removes the item at pop time — if a
 * worker crashes mid-job, it's just gone, no way to detect or retry. This
 * uses the "reliable queue" pattern instead: pop() atomically moves a
 * job's payload from a queue's pending list to a separate processing list
 * (popTailPushHeadBlocking() — genuinely BRPOPLPUSH, suspending the
 * calling Fiber via Revolt, not busy-polling) rather than deleting it
 * outright. ack() removes it from the processing list; release() moves it
 * back onto the pending list. This gives the same at-least-once
 * possibility SqlQueue's `reserved_at` column gives, not a silently
 * weaker guarantee just because the backend differs.
 *
 * Every key is scoped by queue name (`kinetis_queue:{queue}:pending`,
 * `:processing`, `:delayed`) — named queues are genuinely separate Redis
 * lists/sorted sets, not one shared structure with a filter on top.
 *
 * pop($timeoutSeconds, $queues) checking multiple queues in priority
 * order does not use amphp/redis's non-blocking popTailPushHead() (plain
 * RPOPLPUSH) to "peek" each queue first — its declared return type is
 * non-nullable `string`, but Redis returns nil for an empty source list,
 * which throws a TypeError inside amphp/redis itself. Each queue in
 * priority order instead gets a short blocking check via the
 * correctly-nullable popTailPushHeadBlocking(), cycling through the whole
 * list until something's found or the overall timeout elapses.
 *
 * Deliberately not built: a reaper for jobs stuck in a processing list
 * because the worker that popped them crashed before ack()/release()
 * ever ran. That's a real gap, not an oversight — closing it needs a
 * visibility-timeout mechanism (a per-job "reserved at" timestamp plus a
 * periodic scan) this first cut doesn't have yet.
 *
 * Redis has no per-job columns the way SqlQueue has an `attempts` column,
 * so attempts/maxAttempts travel inside the JSON payload itself —
 * {class, args, attempts, maxAttempts} — reread and rewritten by
 * release() on every retry. The stored value is the number of *completed*
 * attempts (0 at push time); QueuedJob::$attempts is always that value
 * plus one.
 */
final readonly class RedisQueue implements QueueInterface
{
    private const PER_QUEUE_POLL_TIMEOUT_SECONDS = 1;

    public function __construct(
        private RedisClient $redis,
    ) {}

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $telemetryToken = Telemetry::global()->jobPushStarted($job::class, $queue);

        try {
            $metadata = Telemetry::global()->jobPushMetadata($telemetryToken);
            $payload = self::encode(JobSerializer::serialize($job), attempts: 0, maxAttempts: $maxAttempts, metadata: $metadata);

            if ($delaySeconds > 0) {
                $this->redis->getSortedSet(self::delayedKey($queue))->add([$payload => (float) (time() + $delaySeconds)]);
            } else {
                $this->redis->getList(self::pendingKey($queue))->pushHead($payload);
            }

            Telemetry::global()->jobPushEnded($telemetryToken, null);
        } catch (Throwable $e) {
            Telemetry::global()->jobPushEnded($telemetryToken, $e);

            throw $e;
        }
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        if ($queues === []) {
            return null;
        }

        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;

        while (true) {
            foreach ($queues as $queue) {
                $this->promoteDelayedJobs($queue);

                $payload = $this->redis->getList(self::pendingKey($queue))
                    ->popTailPushHeadBlocking(self::processingKey($queue), self::PER_QUEUE_POLL_TIMEOUT_SECONDS);

                if ($payload !== null) {
                    /** @var array{class: class-string<Job>, args: array<string, mixed>, attempts: int, maxAttempts: int|null, metadata?: array<string, string>} $decoded */
                    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

                    return new QueuedJob(
                        $decoded['class'],
                        $decoded['args'],
                        handle: $payload,
                        queue: $queue,
                        attempts: $decoded['attempts'] + 1,
                        maxAttempts: $decoded['maxAttempts'],
                        metadata: $decoded['metadata'] ?? [],
                    );
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    return null;
                }
            }
        }
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        $this->removeFromProcessing($job);
    }

    #[\Override]
    public function release(QueuedJob $job): void
    {
        $this->removeFromProcessing($job);

        $payload = self::encode(['class' => $job->class, 'args' => $job->args], attempts: $job->attempts, maxAttempts: $job->maxAttempts, metadata: $job->metadata);
        $this->redis->getList(self::pendingKey($job->queue))->pushHead($payload);
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        $this->removeFromProcessing($job);
    }

    /**
     * Pending plus delayed: a delayed job is waiting on this queue even
     * while its own delay keeps it from being popped yet, so counting it
     * is what makes "how much work is outstanding" match reality. The
     * processing list is excluded — those belong to a worker already.
     */
    #[\Override]
    public function size(string $queue = 'default'): int
    {
        return $this->redis->getList(self::pendingKey($queue))->getSize()
            + $this->redis->getSortedSet(self::delayedKey($queue))->getSize();
    }

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        $size = $this->size($queue);
        $this->redis->delete(self::pendingKey($queue), self::delayedKey($queue));

        return $size;
    }

    private function removeFromProcessing(QueuedJob $job): void
    {
        /** @var string $payload */
        $payload = $job->handle;
        $this->redis->getList(self::processingKey($job->queue))->remove($payload, 1);
    }

    /**
     * @param array{class: class-string, args: array<string, mixed>} $serialized
     */
    /**
     * @param array{class: class-string, args: array<string, mixed>} $serialized
     * @param array<string, string> $metadata
     */
    private static function encode(array $serialized, int $attempts, ?int $maxAttempts, array $metadata = []): string
    {
        return json_encode([...$serialized, 'attempts' => $attempts, 'maxAttempts' => $maxAttempts, 'metadata' => $metadata], JSON_THROW_ON_ERROR);
    }

    /**
     * `getRangeByScore()` (a read) and `remove()` (a write) are two
     * separate Redis commands, not one atomic operation — with N workers
     * calling this concurrently, every one of them can independently read
     * the same ready set before any of them has removed anything from it.
     * `remove()` itself is still atomic per-call and returns the number of
     * members it actually deleted (0 or 1 for a single member here), so
     * checking that return value is what makes the *promotion* atomic:
     * only the one worker whose own `remove()` call actually deleted the
     * member ever pushes it onward. Every other worker's `remove()` on an
     * already-gone member returns 0 and skips it — this is what fixes the
     * bug where every delayed job ran once per polling worker instead of
     * once total.
     */
    private function promoteDelayedJobs(string $queue): void
    {
        $delayed = $this->redis->getSortedSet(self::delayedKey($queue));
        $ready = $delayed->getRangeByScore(ScoreBoundary::negativeInfinity(), ScoreBoundary::inclusive((float) time()));

        foreach ($ready as $payload) {
            if ($delayed->remove($payload) > 0) {
                $this->redis->getList(self::pendingKey($queue))->pushHead($payload);
            }
        }
    }

    private static function pendingKey(string $queue): string
    {
        return "kinetis_queue:{$queue}:pending";
    }

    private static function processingKey(string $queue): string
    {
        return "kinetis_queue:{$queue}:processing";
    }

    private static function delayedKey(string $queue): string
    {
        return "kinetis_queue:{$queue}:delayed";
    }
}
