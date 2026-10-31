<?php

declare(strict_types=1);

namespace Kinetis\Queue\Events;

use Throwable;

/**
 * Dispatched by QueueWorker::processNext() when a job's handle() throws
 * and attempts has reached the effective cap, once the backend has
 * deleted the job through fail().
 *
 * $failedJobId names the record a bound
 * {@see \Kinetis\Queue\FailedJobRecorderInterface} wrote before that
 * deletion — kinetis/queue-failed-jobs' `queue:retry` and `queue:forget`
 * take it. It is null when no recorder is bound, and then this event and
 * the log entry that fires alongside it are the only record of the job.
 *
 * $args is the same redacted array the log entry carries — every
 * constructor parameter marked #[Kinetis\Queue\Attributes\Sensitive]
 * comes through as a fixed placeholder, not the real value.
 */
final readonly class JobFailedPermanently
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public function __construct(
        public string $class,
        public string $queue,
        public int $attempts,
        public Throwable $exception,
        public array $args,
        public ?string $failedJobId,
    ) {}
}
