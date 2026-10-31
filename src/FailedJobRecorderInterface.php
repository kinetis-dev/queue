<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * Where QueueWorker records a job it gives up on, before the backend is
 * told to delete it. Bound by kinetis/queue-failed-jobs; with nothing
 * bound the worker records nothing.
 *
 * record() is a durable write, not an observer: QueueWorker calls it
 * ahead of fail() and settles nothing when it throws, so the backend
 * still holds the job and redelivers it once its reservation expires.
 * An implementation must therefore return only once the record is
 * persisted, and throw on every failure to persist it.
 */
interface FailedJobRecorderInterface
{
    /** Persists the job and returns the record's id. Throws on any failure to persist. */
    public function record(FailedJob $job): string;
}
