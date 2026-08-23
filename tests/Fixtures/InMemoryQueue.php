<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Exception\StaleJobHandleException;
use Kinetis\Queue\Job;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/**
 * A real, array-backed QueueInterface — no Redis, no database — so
 * QueueWorker's own orchestration logic (resolve handle()'s parameters,
 * invoke it, ack on success, release on failure) is tested against real
 * push()/pop()/ack()/release() behavior instead of pre-programmed return
 * values. Ignores $delaySeconds and $timeoutSeconds entirely — no
 * backend-specific timing behavior to fake here, only FIFO order,
 * priority-by-queue-order, and ack/release bookkeeping.
 */
final class InMemoryQueue implements QueueInterface
{
    /** @var array<string, list<array{class: class-string<Job>, args: array<string, mixed>, attempts: int, maxAttempts: int|null}>> */
    private array $pending = [];

    /** @var list<int> */
    public array $acked = [];

    /** @var list<int> */
    public array $released = [];

    /** @var list<int> */
    public array $failed = [];

    private int $nextHandle = 1;

    /**
     * Makes the next release() call throw StaleJobHandleException instead
     * of actually releasing — QueueWorker's own real backend
     * (RedisQueue) can throw this when its conditional Lua transition
     * finds the source entry already gone, and this fixture needs a way
     * to exercise that path without a real Redis.
     */
    public bool $releaseShouldThrowStale = false;

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pending[$queue][] = [...JobSerializer::serialize($job), 'attempts' => 0, 'maxAttempts' => $maxAttempts];
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        foreach ($queues as $queue) {
            if (($this->pending[$queue] ?? []) === []) {
                continue;
            }

            $next = array_shift($this->pending[$queue]);

            return new QueuedJob(
                $next['class'],
                $next['args'],
                handle: $this->nextHandle++,
                queue: $queue,
                attempts: $next['attempts'] + 1,
                maxAttempts: $next['maxAttempts'],
            );
        }

        return null;
    }

    public function ack(QueuedJob $job): void
    {
        /** @var int $handle */
        $handle = $job->handle;
        $this->acked[] = $handle;
    }

    public function release(QueuedJob $job): void
    {
        if ($this->releaseShouldThrowStale) {
            $this->releaseShouldThrowStale = false;

            throw StaleJobHandleException::forRelease($job->queue);
        }

        /** @var int $handle */
        $handle = $job->handle;
        $this->released[] = $handle;
        $this->pending[$job->queue][] = [
            'class' => $job->class,
            'args' => $job->args,
            'attempts' => $job->attempts,
            'maxAttempts' => $job->maxAttempts,
        ];
    }

    public function fail(QueuedJob $job): void
    {
        /** @var int $handle */
        $handle = $job->handle;
        $this->failed[] = $handle;
    }

    public function size(string $queue = 'default'): int
    {
        return \count($this->pending[$queue] ?? []);
    }

    public function clear(string $queue = 'default'): int
    {
        $size = $this->size($queue);
        $this->pending[$queue] = [];

        return $size;
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return ($this->pending[$queue] ?? []) === [];
    }
}
