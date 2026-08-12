<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

final readonly class UnresolvableParameterJob implements Job
{
    public function handle(string $notAClass): void
    {
    }
}
