<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\DisposableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use LogicException;

/**
 * An application's own queue that happens to declare the disposal
 * capability — the case where a call counter is the assertion rather
 * than a throw: the point is that a bootstrap which disposes by type
 * instead of by ownership would close a connection the application
 * opened and still be caught here.
 */
final class DisposableApplicationQueue implements DisposableQueueInterface
{
    public int $disposeCalls = 0;

    #[\Override]
    public function dispose(): void
    {
        ++$this->disposeCalls;
    }

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        throw new LogicException('The queue backend must not be touched.');
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        throw new LogicException('The queue backend must not be touched.');
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        throw new LogicException('The queue backend must not be touched.');
    }

    #[\Override]
    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        throw new LogicException('The queue backend must not be touched.');
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        throw new LogicException('The queue backend must not be touched.');
    }

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        throw new LogicException('The queue backend must not be touched.');
    }
}
