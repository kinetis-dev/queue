<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Fiber;
use Kinetis\Queue\Job;
use function Kinetis\Async\concurrently;

/**
 * A job whose concurrent task suspends with nothing registered to
 * resume it. Kinetis\Async\ConcurrentBatch detects that by the event
 * loop running out of *referenced* watchers while the caller is still
 * parked, so this job is what proves a heartbeat watcher running
 * alongside the handler stays unreferenced: a referenced one would keep
 * the loop alive and turn the deadlock into a hang.
 */
final readonly class DeadlockingConcurrentJob implements Job
{
    public function handle(): void
    {
        concurrently([static function (): void {
            Fiber::suspend();
        }]);
    }
}
