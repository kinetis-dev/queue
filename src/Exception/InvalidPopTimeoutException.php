<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * pop()'s $timeoutSeconds means exactly two things: 0 blocks with no
 * deadline at all, and a positive value blocks for up to that many
 * seconds before returning null. A negative value has no meaning on
 * either side of that split — treating it the same as 0 (unbounded
 * blocking) would silently accept what's very likely a caller's own
 * mistake (a miscomputed remaining-timeout value, for one) rather than
 * surfacing it, so every backend rejects it outright instead.
 */
final class InvalidPopTimeoutException extends InvalidArgumentException
{
    public static function negative(int $timeoutSeconds): self
    {
        return new self(
            "QueueInterface::pop()'s \$timeoutSeconds must be 0 (block with no deadline) or positive "
            . "(block for up to that many seconds), got {$timeoutSeconds}.",
        );
    }
}
