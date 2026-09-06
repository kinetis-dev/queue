<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A nullable enum argument. `?Priority` is still one named type naming
 * the enum's own class, so it states exactly what a stored backing value
 * comes back as and JobSerializer accepts it.
 */
final readonly class NullablePriorityJob implements Job
{
    public function __construct(
        public ?Priority $mode,
    ) {}

    public function handle(): void {}
}
