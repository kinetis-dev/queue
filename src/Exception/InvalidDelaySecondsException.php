<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * push()'s $delaySeconds means exactly two things: 0 pushes immediately,
 * and a positive value delays it by that many seconds. A negative value
 * has no meaning on either side of that split, and would otherwise
 * reach every backend's own storage layer with no coherent effect: an
 * immediate push it never asked for (a Redis list, a RabbitMQ routing
 * key), an already-available timestamp (a SQL `available_at` column), or
 * a value a remote API rejects on its own terms rather than the moment
 * the caller actually made the mistake (SQS's own DelaySeconds).
 */
final class InvalidDelaySecondsException extends InvalidArgumentException
{
    public static function negative(int $delaySeconds): self
    {
        return new self(
            "QueueInterface::push()'s \$delaySeconds must be 0 (push immediately) or positive "
            . "(delay by that many seconds), got {$delaySeconds}.",
        );
    }
}
