<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/**
 * Hands back exactly one caller-supplied QueuedJob, once — for a test
 * that needs to control the popped job's raw shape directly (a class
 * name that deliberately can't autoload, a specific attempts/maxAttempts
 * pair) rather than going through a real Job instance and
 * JobSerializer::serialize()'s own reflection, which requires the class
 * to genuinely exist.
 */
final class PresetQueuedJobQueue implements QueueInterface
{
    private ?QueuedJob $pending;

    /** @var list<mixed> */
    public array $acked = [];

    /** @var list<mixed> */
    public array $released = [];

    /** @var list<mixed> */
    public array $failed = [];

    public function __construct(QueuedJob $job)
    {
        $this->pending = $job;
    }

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        // Not needed by this fixture — the one job it ever hands back is
        // supplied directly to the constructor.
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        $job = $this->pending;
        $this->pending = null;

        return $job;
    }

    public function ack(QueuedJob $job): void
    {
        $this->acked[] = $job->handle;
    }

    public function release(QueuedJob $job): void
    {
        $this->released[] = $job->handle;
    }

    public function fail(QueuedJob $job): void
    {
        $this->failed[] = $job->handle;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->pending === null ? 0 : 1;
    }

    public function clear(string $queue = 'default'): int
    {
        $size = $this->size($queue);
        $this->pending = null;

        return $size;
    }
}
