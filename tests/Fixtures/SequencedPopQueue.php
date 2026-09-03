<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use Throwable;

/**
 * Hands back a caller-supplied sequence of pop() outcomes, one per call —
 * a real QueuedJob, null ("nothing available"), or a Throwable to throw
 * instead. Built for exercising QueueWorker::processNext()'s own handling
 * of what pop() itself can do beyond returning a job: settling a
 * malformed message (Exception\MalformedJobSettledException) and, kept
 * distinct from that, an ordinary transport/infrastructure failure that
 * must still propagate rather than being silently absorbed the same way.
 */
final class SequencedPopQueue implements QueueInterface
{
    /** @var list<QueuedJob|Throwable|null> */
    private array $outcomes;

    /** @var list<mixed> */
    public array $acked = [];

    /** @var list<mixed> */
    public array $released = [];

    /** @var list<mixed> */
    public array $failed = [];

    /**
     * Every pop() call's own arguments, in order — lets a test prove
     * exactly what a caller (QueueWorker::run()'s loop, in particular)
     * actually forwarded, rather than only observing the outcome.
     *
     * @var list<array{int, list<string>}>
     */
    public array $popCalls = [];

    /**
     * @param list<QueuedJob|Throwable|null> $outcomes consumed in order,
     *     one per pop() call; once exhausted, pop() returns null.
     */
    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        // Not needed by this fixture — its outcomes are supplied directly
        // to the constructor, not accumulated via push().
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        $this->popCalls[] = [$timeoutSeconds, $queues];

        if ($this->outcomes === []) {
            return null;
        }

        $next = array_shift($this->outcomes);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
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
        return \count($this->outcomes);
    }

    public function clear(string $queue = 'default'): int
    {
        $count = \count($this->outcomes);
        $this->outcomes = [];

        return $count;
    }
}
