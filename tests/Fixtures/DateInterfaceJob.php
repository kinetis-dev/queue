<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use DateTimeInterface;
use Kinetis\Queue\Job;

/**
 * A DateTimeInterface-typed argument. The interface admits both
 * DateTime and DateTimeImmutable, so it does not state which one a
 * stored timestamp should come back as — JobSerializer requires exactly
 * DateTimeImmutable.
 */
final readonly class DateInterfaceJob implements Job
{
    public function __construct(
        public DateTimeInterface $at,
    ) {}

    public function handle(): void {}
}
