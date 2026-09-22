<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A job that yields to the event loop for a while and then records that
 * it finished — the shape a real slow job takes, and the only shape a
 * reservation renewal can happen during.
 */
final readonly class YieldingJob implements Job
{
    public function __construct(
        public float $seconds,
    ) {}

    public function handle(Recorder $recorder): void
    {
        LoopDelay::seconds($this->seconds);

        $recorder->record('job:done');
    }
}
