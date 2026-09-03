<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use RuntimeException;

/**
 * Throws unconditionally from its own constructor — never constructed
 * via JobSerializer::serialize() (which reads an already-live instance's
 * properties, never calling the constructor itself), only ever reached
 * via JobSerializer::deserializeJob()/deserialize() directly with
 * hand-crafted args, proving a constructor failure is wrapped in
 * JobReconstructionException::constructionFailed() rather than
 * propagating as a raw, uncontextualized Throwable.
 */
final readonly class ThrowsInConstructorJob implements Job
{
    public function __construct(
        public string $value,
    ) {
        throw new RuntimeException('the constructor itself always fails');
    }

    public function handle(): void
    {
    }
}
