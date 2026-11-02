<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use RuntimeException;

/**
 * A job whose handler takes a measurable wall-clock time before it
 * returns or throws.
 */
final readonly class SleepingJob implements Job
{
    public function __construct(
        public int $microseconds,
        public bool $fail = false,
    ) {}

    public function handle(): void
    {
        usleep($this->microseconds);

        if ($this->fail) {
            throw new RuntimeException('slow failure');
        }
    }
}
