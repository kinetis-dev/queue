<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A single, deliberately untyped constructor argument — reused across
 * many WireValue/JobSerializer conformance tests instead of one narrow
 * fixture per case, since the thing under test is always "does this
 * particular value survive the round trip," never anything job-specific.
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
