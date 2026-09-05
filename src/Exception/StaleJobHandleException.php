<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use Kinetis\Queue\JobSettlement;
use RuntimeException;

/**
 * A settlement found no live reservation to act on: the delivery this
 * QueuedJob::$handle names is no longer the backend's — it was already
 * acked, released, or failed through another call, or reclaimed after
 * its reservation expired and handed to another worker.
 *
 * Raised by ack(), release() and fail() alike, carrying which of the
 * three was attempted in $operation. The state it reports is identical
 * in all three cases — nothing this call wanted is left to do, and
 * nothing it wanted was written — while what a caller reports about it
 * is not, which is why the operation travels with the exception rather
 * than being inferred from the call site that caught it. A backend
 * raises this only for a settlement it fences; see QueuedJob's own
 * docblock for the delivery-receipt contract the fence rests on, and
 * each backend's docblock for which of its settlements carry one.
 *
 * QueueWorker catches this on all three paths, keeps the loop running,
 * and reports the lost ownership through Events\JobSettlementLost rather
 * than the success/released/permanent-failure event the settlement would
 * have earned had it landed.
 */
final class StaleJobHandleException extends RuntimeException
{
    private function __construct(
        public readonly JobSettlement $operation,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forSettlement(JobSettlement $operation, string $queue): self
    {
        return new self(
            $operation,
            "{$operation->value}() found no live reservation for this delivery on the \"{$queue}\" queue — it was "
            . 'already settled through another call, or reclaimed after its reservation expired. Nothing was written.',
        );
    }
}
