<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Closure;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\FailedJobRecorderInterface;
use RuntimeException;

/**
 * Keeps every recorded FailedJob in $recorded and answers with ids
 * "failed-1", "failed-2", … . $onRecord runs before the job is kept, so a
 * test can observe what the backend had seen at the moment of record().
 */
final class InMemoryFailedJobRecorder implements FailedJobRecorderInterface
{
    /** @var list<FailedJob> */
    public array $recorded = [];

    public bool $shouldThrow = false;

    /** @var ?Closure(FailedJob): void */
    public ?Closure $onRecord = null;

    #[\Override]
    public function record(FailedJob $job): string
    {
        if ($this->onRecord !== null) {
            ($this->onRecord)($job);
        }

        if ($this->shouldThrow) {
            throw new RuntimeException('The failed-job store refused the write.');
        }

        $this->recorded[] = $job;

        return 'failed-' . \count($this->recorded);
    }
}
