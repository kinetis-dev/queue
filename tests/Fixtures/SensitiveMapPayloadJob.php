<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

/**
 * A #[Sensitive] argument whose own value is a map, not a scalar — for
 * proving a rejection nested inside it (a map key, or an unsupported
 * value behind one) never surfaces that nested detail in an exception
 * message, unlike an ordinary, unmarked map argument.
 */
final readonly class SensitiveMapPayloadJob implements Job
{
    public function __construct(
        #[Sensitive]
        public mixed $tokens,
    ) {}

    public function handle(): void
    {
    }
}
