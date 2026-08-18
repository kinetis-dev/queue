<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;
use RuntimeException;

/**
 * Mixes marked and unmarked constructor arguments, so a test can tell
 * redaction from a blanket wipe. It throws because the only path that
 * logs a job's arguments at all is the one where it failed.
 */
final readonly class SensitiveFailingJob implements Job
{
    public function __construct(
        public int $userId,
        #[Sensitive]
        public string $email,
        #[Sensitive]
        public string $resetToken,
    ) {}

    public function handle(): void
    {
        throw new RuntimeException('delivery failed');
    }
}
