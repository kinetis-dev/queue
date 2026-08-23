<?php

declare(strict_types=1);

namespace Kinetis\Queue\Events;

use Throwable;

/**
 * Dispatched by QueueWorker::processNext() when a job's handle() throws
 * and attempts has reached the effective cap, so the job is fail()'d
 * instead of retried — this is the only record of it beyond the log
 * entry that fires alongside it, since there's no dead-letter table or
 * queue to inspect afterward.
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
    ) {}
}
