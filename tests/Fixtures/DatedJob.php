<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use DateTimeImmutable;
use Kinetis\Queue\Job;

/**
 * A DateTimeImmutable-typed argument that accepts a subclass instance,
 * so JobSerializer's exact-class rule can be exercised from a real
 * declared type rather than from an untyped one.
 */
final readonly class DatedJob implements Job
{
    public function __construct(
        public DateTimeImmutable $at,
    ) {}

    public function handle(): void {}
}
