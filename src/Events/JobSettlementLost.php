<?php

declare(strict_types=1);

namespace Kinetis\Queue\Events;

use Kinetis\Queue\Exception\StaleJobHandleException;
use Kinetis\Queue\JobSettlement;
use Throwable;

/**
 * Dispatched by QueueWorker::processNext() when the backend rejects a
 * job's durable transition as stale — the delivery this worker held was
 * already settled elsewhere, or reclaimed after its reservation expired.
 * It replaces the JobSucceeded/JobReleased/JobFailedPermanently the
 * settlement would have earned: no durable transition was confirmed, so
 * none of those three would be true.
 *
 * $operation is which settlement was attempted, and $stale the backend's
 * own rejection of it. $failure is the job's own exception on the
 * release/fail paths and null on the ack path, where handle() returned —
 * the job's outcome is unchanged by losing the delivery, only the
 * worker's ability to record it is.
 *
 * A listener's useful reading of this is "another worker may run, or may
 * already have run, this same job" — at-least-once delivery becoming
 * visible rather than a new failure mode.
 */
final readonly class JobSettlementLost
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public string $queue,
        public int $attempts,
        public JobSettlement $operation,
        public StaleJobHandleException $stale,
        public ?Throwable $failure = null,
    ) {}
}
