<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A union-typed constructor argument. A union names more than one class,
 * so nothing in it says which one a stored scalar should come back as —
 * JobSerializer rejects an enum case or a date declared this way.
 */
final readonly class UnionArgumentJob implements Job
{
    public function __construct(
        public Priority|Severity $mode,
    ) {}

    public function handle(): void {}
}
