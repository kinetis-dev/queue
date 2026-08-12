<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use RuntimeException;

final readonly class FailingJob implements Job
{
    public function __construct(
        public string $reason,
    ) {}

    public function handle(): void
    {
        throw new RuntimeException($this->reason);
    }
}
