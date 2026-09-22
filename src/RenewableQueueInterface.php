<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * Extending a live reservation while its job is still running — a
 * capability a backend has only when a delivery it handed out is held
 * for a finite window it can push forward.
 *
 * RedisQueue, SqlQueue and Kinetis\QueueSqs\SqsQueue declare this.
 * RabbitMqQueue and SyncQueue do not: RabbitMQ's channel already holds
 * the unacknowledged delivery for as long as the connection lives, and
 * SyncQueue runs the job inline with no reservation to extend.
 *
 * Extends QueueInterface rather than sitting beside it: anything holding
 * this holds a whole queue. QueueWorker asks with an instanceof once, in
 * its constructor, and drives the renewals itself. Application jobs
 * never see their receipt and get no job-context API of their own — a
 * renewal is worker policy, not something a handler opts into.
 */
interface RenewableQueueInterface extends QueueInterface
{
    /**
     * How long, in whole seconds, this backend holds a delivery before
     * another worker may take the job over. Always positive.
     *
     * QueueWorker reads it to pace its renewals; a deployment sets it
     * through each backend's own configuration.
     */
    public function visibilityTimeoutSeconds(): int;

    /**
     * Pushes this delivery's reservation window out to
     * visibilityTimeoutSeconds() from now — best effort, and fenced on
     * the receipt QueuedJob::$handle names, so a delivery that is
     * already over is never extended and no other worker's reservation
     * is touched.
     *
     * **Returning says nothing about whether the delivery was still
     * current.** MySQL and Redis both report zero changed rows or
     * members for a renewal that writes the same value the previous one
     * wrote, which a renewal at the resolution of a one-second clock
     * routinely does, so zero cannot truthfully mean stale. There is no
     * Exception\StaleJobHandleException here and no JobSettlement case:
     * renewal is not a settlement and consumes nothing.
     *
     * Transport and backend errors propagate unchanged, exactly as they
     * do from every other operation. QueueWorker contains them — see
     * that class for what a failed renewal does to a job in flight.
     *
     * Repeated calls are supported, and a failure says nothing about
     * whether a later attempt will fail — but this is not idempotent:
     * every successful call moves the reservation window forward from
     * that call.
     *
     * May return synchronously when no I/O is needed. Any I/O must
     * suspend its Fiber rather than block the event-loop thread, and
     * must be bounded by the backend or client's own operation timeout:
     * QueueWorker joins a call still in flight before it settles the
     * delivery, and cannot safely abandon one.
     */
    public function renew(QueuedJob $job): void;
}
