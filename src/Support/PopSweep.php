<?php

declare(strict_types=1);

namespace Kinetis\Queue\Support;

use Kinetis\Queue\Exception\InvalidPopSweepConfigurationException;
use Kinetis\Queue\QueueContract;
use Kinetis\Queue\QueuedJob;

/**
 * The one shared priority/timeout algorithm every backend's pop() runs
 * through, instead of each hand-rolling its own foreach-with-a-blocking-
 * wait-per-queue loop — which is exactly what made the old, independent
 * versions of that loop inconsistently buggy: an empty first queue could
 * consume the whole timeout budget before a lower-priority queue already
 * holding a job was ever checked, and a real deadline could be
 * overshot by up to one full per-queue wait.
 *
 * Every full sweep runs in two phases:
 *
 * 1. An immediate, non-blocking, priority-ordered probe of every queue —
 *    $probe($queue, 0.0) for each, in list order. This alone is what
 *    fixes the "first queue consumes the budget" bug: a job already
 *    waiting anywhere is always found here, before any blocking wait
 *    ever runs, regardless of which position it's in.
 * 2. Only once phase 1 finds nothing anywhere: if $probeCanBlock, one
 *    bounded wait per queue, in the same priority order, each capped by
 *    both $waitCapSeconds and whatever remains of the overall deadline
 *    — giving a backend with a native blocking primitive (Redis, SQS)
 *    something real to wait on instead of busy-spinning through phase 1
 *    forever. A backend with no blocking primitive at all (RabbitMQ,
 *    SqlQueue's own bespoke loop, which doesn't use this class) instead
 *    paces retries via $sleep, bounded the same way.
 *
 * $probe($queue, $waitBudgetSeconds) is trusted to spend no more than
 * roughly $waitBudgetSeconds of real time before returning — this class
 * never measures how long a single call actually took, only re-checks
 * the deadline before starting the next one. A backend whose native
 * primitive has coarser granularity than a fractional second (Redis and
 * SQS both do) is responsible for its own rounding inside $probe itself,
 * including never passing a literal 0 to a primitive where 0 means
 * "block forever" rather than "don't block at all" — see RedisQueue's
 * and SqsQueue's own probe closures for the two different ways that
 * gets handled.
 *
 * What this class deliberately does not attempt: once $probe returns a
 * job, it is returned immediately, with no attempt to re-check
 * higher-priority queues first. Every backend's own probe already
 * reserves/commits atomically the instant it finds something (Redis's
 * move to a processing list, SQS's receive-triggered invisibility,
 * RabbitMQ's basic.get, SqlQueue's own row-level lock) — there is no
 * backend here with a "peek without reserving" primitive to recheck
 * from, so attempting it would mean un-reserving an already-committed
 * job first, a real correctness risk this class does not take on. A job
 * that arrives on a higher-priority queue while this class is blocked
 * inside a lower-priority queue's own phase-2 probe is picked up on the
 * very next full sweep instead, bounded by at most $waitCapSeconds times
 * the number of queues still left to probe in the current one.
 */
final class PopSweep
{
    private function __construct() {}

    /**
     * Validates its own arguments rather than trusting a caller to have
     * already run QueueContract first — this class is called from
     * RedisQueue, SqsQueue, and RabbitMqQueue, three other published
     * packages beyond this one, so its own liveness guarantee (an
     * unbounded pop() blocks until something's available, never returns
     * null on its own) has to hold regardless of what a caller outside
     * this package's own backends does. $timeoutSeconds/$queues go
     * through the exact same QueueContract::assertValidPopArguments()
     * every backend's own pop() already calls — redundant, not wasteful,
     * for the four backends built on this class, and the actual defense
     * for anything else. $waitCapSeconds must be a real, positive,
     * finite bound: it's what every per-queue wait (probeCanBlock: true)
     * or paced sleep() (probeCanBlock: false) is capped by, so a
     * non-positive or non-finite value would silently turn "block until
     * found" into "return null on the very first sweep" instead.
     *
     * @param list<string> $queues checked in the given order, on every sweep
     * @param callable(string, float): (QueuedJob|null) $probe
     * @param callable(float): void $sleep invoked, between full sweeps,
     *     only when $probeCanBlock is false
     * @param (callable(): float)|null $clock defaults to microtime(true)
     *     — injectable so timeout behavior can be tested deterministically,
     *     paired with a $sleep that advances the same fake clock rather
     *     than actually suspending
     */
    public static function run(
        int $timeoutSeconds,
        array $queues,
        callable $probe,
        bool $probeCanBlock,
        float $waitCapSeconds,
        callable $sleep,
        ?callable $clock = null,
    ): ?QueuedJob {
        QueueContract::assertValidPopArguments($timeoutSeconds, $queues);

        if (!is_finite($waitCapSeconds) || $waitCapSeconds <= 0.0) {
            throw InvalidPopSweepConfigurationException::waitCapMustBePositiveAndFinite($waitCapSeconds);
        }

        if ($queues === []) {
            return null;
        }

        $now = $clock ?? static fn (): float => microtime(true);
        $deadline = $timeoutSeconds > 0 ? $now() + $timeoutSeconds : null;

        while (true) {
            foreach ($queues as $queue) {
                $job = $probe($queue, 0.0);

                if ($job !== null) {
                    return $job;
                }
            }

            if ($deadline !== null && $now() >= $deadline) {
                return null;
            }

            if ($probeCanBlock) {
                foreach ($queues as $queue) {
                    if ($deadline !== null && $now() >= $deadline) {
                        return null;
                    }

                    $remaining = $deadline !== null ? $deadline - $now() : $waitCapSeconds;
                    $waitBudget = min($waitCapSeconds, $remaining);

                    if ($waitBudget <= 0.0) {
                        return null;
                    }

                    $job = $probe($queue, $waitBudget);

                    if ($job !== null) {
                        return $job;
                    }
                }

                if ($deadline !== null && $now() >= $deadline) {
                    return null;
                }
            } else {
                $remaining = $deadline !== null ? $deadline - $now() : $waitCapSeconds;
                $sleepFor = min($waitCapSeconds, max(0.0, $remaining));

                if ($sleepFor <= 0.0) {
                    return null;
                }

                $sleep($sleepFor);
            }
        }
    }
}
