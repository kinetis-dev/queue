<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * $maxAttempts means exactly two things, whether it arrives as
 * push()'s own argument or as QueuedJob::$maxAttempts read back at pop()
 * time: null defers to the processing QueueWorker's own
 * $defaultMaxAttempts, and 0 or a positive value is the effective cap
 * itself (0 meaning no retries — a job that fails once is given up on
 * immediately). A negative value has no meaning either way: it would
 * still reach QueueWorker's own `$queuedJob->attempts >= $maxAttempts`
 * check, which classifies a job's very first attempt as already
 * exhausted, since $attempts starts at 1.
 */
final class InvalidMaxAttemptsException extends InvalidArgumentException
{
    public static function negative(int $maxAttempts): self
    {
        return new self(
            "\$maxAttempts must be null (defer to the worker's own default) or non-negative "
            . "(0 or more — the effective retry cap), got {$maxAttempts}.",
        );
    }
}
