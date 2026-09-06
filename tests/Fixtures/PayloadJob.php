<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A single untyped constructor argument, reused across the
 * JobSerializer wire-value tests rather than one narrow fixture per
 * case: what is under test is always whether a particular value
 * survives the round trip, never anything job-specific.
 */
final readonly class PayloadJob implements Job
{
    public function __construct(
        public mixed $payload,
    ) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->record(get_debug_type($this->payload));
    }
}
