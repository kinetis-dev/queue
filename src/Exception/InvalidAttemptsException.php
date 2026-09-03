<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * QueuedJob::$attempts is the attempt number a given pop() represents —
 * 1-indexed, 1 on the first attempt, 2 after one release(), and so on.
 * There is no attempt zero: a job that has never been attempted has not
 * been popped at all, so a value below 1 has no meaning QueueWorker's own
 * `attempts >= maxAttempts` exhaustion check could act on correctly.
 */
final class InvalidAttemptsException extends InvalidArgumentException
{
    public static function belowOne(int $attempts): self
    {
        return new self(
            "QueuedJob::\$attempts must be 1 or greater (1-indexed: 1 on the first attempt), got {$attempts}.",
        );
    }
}
