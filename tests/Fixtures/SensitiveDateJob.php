<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use DateTimeImmutable;
use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

/**
 * A #[Sensitive] date argument, for the path where a stored value fails
 * to parse back into the type its parameter declares: PHP's own date
 * parser quotes the string it was handed, so its exception must not
 * reach a log line through the reconstruction failure.
 */
final readonly class SensitiveDateJob implements Job
{
    public function __construct(
        #[Sensitive]
        public DateTimeImmutable $bornOn,
    ) {}

    public function handle(): void {}
}
