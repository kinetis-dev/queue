<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by a durable backend's own pop() once it has reserved a message
 * that turned out to be malformed while being decoded into a QueuedJob —
 * by the time this is thrown, that message has already been settled
 * permanently using the same backend's own fail()-equivalent primitive
 * (an exact-payload LREM off Redis's processing list, a SQL row DELETE,
 * SQS's DeleteMessage, RabbitMQ's nack(requeue: false) — see
 * QueueContract::settleIfMalformed(), which every backend routes through
 * to produce this), so there is nothing left for a caller to do to the
 * message itself.
 *
 * QueueWorker catches this specifically — narrower than a blanket catch
 * around the whole pop() call — so an ordinary transport/infrastructure
 * failure (a dropped connection, a backend genuinely unreachable) still
 * propagates and stops the worker exactly as it always has, rather than
 * being silently treated as if it were a settled malformed job.
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
