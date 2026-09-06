<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * A reserved message could not be decoded and has already been settled
 * permanently by the backend that reserved it.
 *
 * Every durable backend reserves before it decodes, so a decode failure
 * leaves a real reservation with nothing to release it. Settling first
 * and reporting through this type is what stops a poison message from
 * being stranded forever (backends with no reclaim mechanism) or
 * replayed forever (backends that have one), since the same bytes fail
 * the same way on every retry. QueueWorker catches this specifically,
 * logs it, and moves on; a transport failure is a different type and
 * still stops the worker.
 */
final class MalformedJobSettledException extends RuntimeException
{
    public function __construct(
        public readonly string $queue,
        Throwable $decodeFailure,
    ) {
        parent::__construct(
            "A malformed job on queue \"{$queue}\" was found and permanently removed: {$decodeFailure->getMessage()}",
            previous: $decodeFailure,
        );
    }
}
