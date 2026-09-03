<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\QueuedJob;
use LogicException;

/**
 * Every method throws immediately — a stronger proof that a caller never
 * reaches the real queue backend at all than checking a call counter
 * after the fact would be: a broken validation-before-side-effects
 * ordering surfaces as this exception, not a silently-passing assertion.
 */
final class NeverCalledQueue implements QueueInterface
{
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
    public function release(QueuedJob $job): void
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

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        throw new LogicException('The queue backend must not be touched.');
    }
}
