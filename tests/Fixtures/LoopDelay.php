<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Revolt\EventLoop;

/**
 * A Fiber-suspending delay that works from {main} as well as from
 * inside a Fiber — the one thing the renewal tests need that
 * Kinetis\Async\Timer cannot give them, since its raw Fiber::suspend()
 * has nowhere to suspend to when QueueWorker::processNext() is driven
 * straight from a test method.
 *
 * Suspending is the whole point: a handler that never yields is a
 * handler no heartbeat can renew, so a fixture job that merely blocked
 * would prove nothing about the tick the test is waiting for.
 */
final class LoopDelay
{
    public static function seconds(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();

        EventLoop::delay($seconds, static function () use ($suspension): void {
            $suspension->resume();
        });

        $suspension->suspend();
    }
}
