<?php

declare(strict_types=1);

namespace Kinetis\Queue\Events;

use Throwable;

/**
 * Dispatched by QueueWorker::processNext() when a job's handle() throws
 * but attempts hasn't yet reached the effective cap, so the job goes back
 * onto the queue for another try. Fires only once the backend has
 * accepted that release. Where it answers with
 * Exception\StaleJobHandleException instead, this worker wrote nothing —
 * the delivery it held was settled elsewhere, or reclaimed after its
 * reservation expired and handed on as another delivery — so
 * JobSettlementLost is dispatched in place of this event.
 *
 * No job arguments here, unlike JobFailedPermanently: the payload is
 * still held by the backend at this point, so there's nothing this event
 * could recover that a later JobFailedPermanently/JobSucceeded for the
 * same job wouldn't already carry.
 */
final readonly class JobReleased
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public string $queue,
        public int $attempts,
        public Throwable $exception,
    ) {}
}
