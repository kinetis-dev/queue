<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;
use RuntimeException;

/**
 * Validates a #[Sensitive] argument in its own constructor and quotes it
 * when rejecting it — what a job checking its own input routinely does.
 * That message and its exception are what must not reach a log through
 * the reconstruction failure.
 */
final readonly class SensitiveConstructorFailureJob implements Job
{
    public function __construct(
        #[Sensitive]
        public string $apiKey,
    ) {
        throw new RuntimeException("rejected the key \"{$apiKey}\"");
    }

    public function handle(): void
    {
    }
}
