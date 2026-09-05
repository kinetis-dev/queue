<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * The three durable transitions a popped job can end in — the vocabulary
 * QueueWorker, Exception\StaleJobHandleException,
 * Events\JobSettlementLost and Kinetis\Instrumentation\TelemetryInterface
 * all name the same operation by.
 *
 * Each case's value is the string that interface's own jobFinished()
 * $outcome takes, so an operation identified here reaches telemetry
 * unchanged rather than through a second mapping that could drift from
 * this one.
 */
enum JobSettlement: string
{
    /** The job's handle() returned. */
    case Ack = 'ack';

    /** The job's handle() threw with attempts left under the cap. */
    case Release = 'release';

    /** The job's handle() threw with attempts at the cap. */
    case Fail = 'fail';
}
