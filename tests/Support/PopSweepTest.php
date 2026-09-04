<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Support;

use Kinetis\Queue\Exception\InvalidPopSweepConfigurationException;
use Kinetis\Queue\Exception\InvalidPopTimeoutException;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\Support\PopSweep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared queue-pop conformance suite: PopSweep is the one algorithm
 * every real backend's pop() delegates to (Redis/SQS directly,
 * RabbitMQ the same way, SqlQueue via its own bespoke single-query loop
 * instead — see that class's own docblock for why), so proving its
 * priority/timeout/no-busy-spin properties here, once, deterministically
 * and with no real sleep, is what proves them for every backend built on
 * it. A fake probe plus a fake clock/sleep pair sharing one mutable
 * "now" value stands in for a real backend and real wall-clock time
 * throughout — for a $probeCanBlock: true scenario, the probe closure
 * itself is what must advance the shared clock when handed a non-zero
 * wait budget, mirroring a real blocking primitive genuinely spending
 * that time; PopSweep never calls $sleep() in that mode at all.
 */
final class PopSweepTest extends TestCase
{
    /**
     * $state is a stdClass, not an array: PHP objects are handle
     * semantics, so every holder of $state — both closures below, and
     * whatever the caller destructures the return value into — shares
     * the exact same mutable instance with no reference gymnastics
     * needed. An array would need every closure to capture it via an
     * explicit `use (&$state)`, and the caller's own destructuring
     * assignment to also request a reference — brittle in a way that
     * failed outright on the first real run, either silently returning
     * a stale snapshot (an arrow function's implicit by-value capture,
     * caught as an infinite loop when $clock() never saw $sleep()'s own
     * updates) or a PHP notice ("Attempting to set reference to non
     * referenceable value", once the array capture was fixed but the
     * caller's own destructuring still tried to bind a reference through
     * a plain function-call return value).
     *
     * @return array{0: callable(): float, 1: callable(float): void, 2: object{now: float}}
     */
    private function fakeClock(float $start = 1_000.0): array
    {
        $state = (object) ['now' => $start];

        $clock = static function () use ($state): float {
            return $state->now;
        };
        $sleep = static function (float $seconds) use ($state): void {
            $state->now += $seconds;
        };

        return [$clock, $sleep, $state];
    }

    private function job(string $queue): QueuedJob
    {
        return new QueuedJob('Fixture\\Job', [], handle: $queue, queue: $queue);
    }

    public function test_empty_queue_list_returns_null_immediately_without_probing(): void
    {
        $probed = 0;
        $result = PopSweep::run(
            timeoutSeconds: 5,
            queues: [],
            probe: function () use (&$probed): ?QueuedJob {
                $probed++;

                return null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('sleep() must never be called for an empty queue list.');
            },
        );

        self::assertNull($result);
        self::assertSame(0, $probed);
    }

    /**
     * A job ready in the *last* queue of a three-queue list is still
     * found on the very first, immediate, non-blocking sweep — the
     * direct proof that an empty higher-priority queue never delays
     * checking a lower one, the core bug this class exists to fix.
     */
    public function test_a_ready_job_at_the_last_priority_position_is_found_on_the_immediate_sweep(): void
    {
        [$clock] = $this->fakeClock();
        $probedQueues = [];

        $result = PopSweep::run(
            timeoutSeconds: 5,
            queues: ['high', 'default', 'low'],
            probe: function (string $queue, float $wait) use (&$probedQueues): ?QueuedJob {
                $probedQueues[] = [$queue, $wait];

                return $queue === 'low' ? $this->job('low') : null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('A ready job must be found on the immediate sweep, before any wait.');
            },
            clock: $clock,
        );

        self::assertNotNull($result);
        self::assertSame('low', $result->queue);
        // Every probe on the winning sweep used wait=0.0 — the immediate,
        // non-blocking phase — and stopped the instant "low" answered.
        self::assertSame([['high', 0.0], ['default', 0.0], ['low', 0.0]], $probedQueues);
    }

    /**
     * @return list<array{string}>
     */
    public static function priorityPositions(): array
    {
        return [['first'], ['second'], ['third']];
    }

    #[DataProvider('priorityPositions')]
    public function test_a_ready_job_is_found_at_every_priority_position(string $winner): void
    {
        [$clock] = $this->fakeClock();
        $queues = ['first', 'second', 'third'];

        $result = PopSweep::run(
            timeoutSeconds: 5,
            queues: $queues,
            probe: fn (string $queue): ?QueuedJob => $queue === $winner ? $this->job($queue) : null,
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('A ready job must be found without ever needing to sleep.');
            },
            clock: $clock,
        );

        self::assertNotNull($result);
        self::assertSame($winner, $result->queue);
    }

    /**
     * probeCanBlock: true means PopSweep never calls $sleep() itself —
     * the probe closure below is what simulates a real blocking
     * primitive spending its own offered wait budget, by advancing the
     * shared clock exactly that much whenever it's given one.
     */
    public function test_all_queues_empty_returns_null_once_the_deadline_passes(): void
    {
        [$clock, $sleep, $state] = $this->fakeClock();

        $result = PopSweep::run(
            timeoutSeconds: 3,
            queues: ['default'],
            probe: function (string $queue, float $wait) use ($sleep): ?QueuedJob {
                if ($wait > 0.0) {
                    $sleep($wait);
                }

                return null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('probeCanBlock: true must never call sleep() itself.');
            },
            clock: $clock,
        );

        self::assertNull($result);
        // Bounded per-queue waits (min(waitCapSeconds, remaining) each)
        // land the clock exactly on the 3-second deadline, not past it —
        // no material overshoot.
        self::assertSame(1_003.0, $state->now);
    }

    /**
     * A job that arrives only after the immediate sweep has already
     * found nothing everywhere — the probe closure itself starts
     * returning a job once enough simulated time has passed, standing
     * in for "a real backend's blocking wait genuinely woke up because
     * something new showed up," not because it timed out.
     */
    public function test_a_job_that_arrives_mid_wait_is_returned_without_waiting_out_the_full_timeout(): void
    {
        [$clock, $sleep, $state] = $this->fakeClock();

        $result = PopSweep::run(
            timeoutSeconds: 10,
            queues: ['default'],
            probe: function (string $queue, float $wait) use ($sleep, $state): ?QueuedJob {
                if ($wait > 0.0) {
                    $sleep($wait);
                }

                // "Arrives" once 2.5 simulated seconds have passed.
                return $state->now >= 1_002.5 ? $this->job($queue) : null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('probeCanBlock: true must never call sleep() itself.');
            },
            clock: $clock,
        );

        self::assertNotNull($result);
        // Found well before the full 10-second timeout would have
        // elapsed — proving the sweep didn't just block for the entire
        // budget on one queue before noticing.
        self::assertLessThan(1_010.0, $state->now);
    }

    /**
     * A 1-second timeout, with the immediate (wait=0) sweep itself
     * costing 0.6 simulated seconds (real network latency to an empty
     * queue, say) — leaving only 0.4s of real budget for the one
     * bounded-wait probe that follows. That probe must be offered
     * exactly the remaining 0.4s, never the full 1.0s cap.
     */
    public function test_a_sub_per_queue_cap_timeout_offers_only_the_remaining_budget(): void
    {
        [$clock, $sleep] = $this->fakeClock();
        $offeredWaits = [];
        $immediateProbed = false;

        $result = PopSweep::run(
            timeoutSeconds: 1,
            queues: ['default'],
            probe: function (string $queue, float $wait) use (&$offeredWaits, &$immediateProbed, $sleep): ?QueuedJob {
                $offeredWaits[] = $wait;

                if ($wait === 0.0 && !$immediateProbed) {
                    // The immediate sweep costs 0.6s of real latency —
                    // once. A later sweep's own immediate probe (which
                    // this scenario never reaches, but a less-patient
                    // probe implementation might) must not re-charge it.
                    $immediateProbed = true;
                    $sleep(0.6);
                } elseif ($wait > 0.0) {
                    $sleep($wait);
                }

                return null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('probeCanBlock: true must never call sleep() itself.');
            },
            clock: $clock,
        );

        self::assertNull($result);
        self::assertCount(2, $offeredWaits);
        self::assertSame(0.0, $offeredWaits[0]);
        // A float subtraction artifact (1001.0 - 1000.6 in IEEE 754
        // binary floating point), not a real discrepancy — the deadline
        // arithmetic itself is exact to well under a millisecond, which
        // is all a real wait budget needs to be.
        self::assertEqualsWithDelta(0.4, $offeredWaits[1], 0.0001);
    }

    /**
     * Five queues, one shared 2-second deadline — none of the
     * lower-priority queues' own bounded waits are allowed to inflate
     * the total past that deadline just because there happen to be more
     * of them to check in one sweep.
     */
    public function test_multiple_queues_share_one_deadline_without_compounding_the_wait(): void
    {
        [$clock, $sleep, $state] = $this->fakeClock();

        $result = PopSweep::run(
            timeoutSeconds: 2,
            queues: ['a', 'b', 'c', 'd', 'e'],
            probe: function (string $queue, float $wait) use ($sleep): ?QueuedJob {
                if ($wait > 0.0) {
                    $sleep($wait);
                }

                return null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('probeCanBlock: true must never call sleep() itself.');
            },
            clock: $clock,
        );

        self::assertNull($result);
        self::assertSame(1_002.0, $state->now);
    }

    /**
     * timeoutSeconds: 0 means "block with no deadline" — the sweep must
     * keep going, paced by real bounded waits (never a busy-spin), until
     * something answers, however many sweeps that takes.
     */
    public function test_zero_timeout_blocks_with_no_deadline_until_something_is_found(): void
    {
        [$clock, $sleep, $state] = $this->fakeClock();
        $blockingWaits = 0;

        $result = PopSweep::run(
            timeoutSeconds: 0,
            queues: ['default'],
            probe: function (string $queue, float $wait) use ($sleep, &$blockingWaits): ?QueuedJob {
                if ($wait === 0.0) {
                    return null;
                }

                $sleep($wait);
                $blockingWaits++;

                return $blockingWaits >= 5 ? $this->job($queue) : null;
            },
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static function (): void {
                self::fail('probeCanBlock: true must never call sleep() itself.');
            },
            clock: $clock,
        );

        self::assertNotNull($result);
        self::assertSame(5, $blockingWaits);
        self::assertSame(1_005.0, $state->now);
    }

    /**
     * A backend with no native blocking primitive at all (RabbitMQ) —
     * every probe call is instant regardless of the wait budget it's
     * offered, and pacing between full sweeps happens entirely through
     * sleep() instead.
     */
    public function test_a_non_blocking_backend_paces_retries_through_sleep_instead_of_probe(): void
    {
        [$clock, $sleep, $state] = $this->fakeClock();
        $probedWaits = [];

        $result = PopSweep::run(
            timeoutSeconds: 3,
            queues: ['default'],
            probe: function (string $queue, float $wait) use (&$probedWaits): ?QueuedJob {
                $probedWaits[] = $wait;

                return null;
            },
            probeCanBlock: false,
            waitCapSeconds: 1.0,
            sleep: $sleep,
            clock: $clock,
        );

        self::assertNull($result);
        self::assertSame(1_003.0, $state->now);
        // Every probe offered 0.0 — a non-blocking backend never gets a
        // wait budget to spend, since it has nothing to spend it on.
        self::assertSame(array_fill(0, \count($probedWaits), 0.0), $probedWaits);
    }

    /**
     * The same "found mid-wait" property as the blocking case, but for a
     * non-blocking backend: the job shows up between two paced sleeps,
     * not inside a blocking probe call itself.
     */
    public function test_a_non_blocking_backend_finds_a_job_that_arrives_between_paced_sweeps(): void
    {
        [$clock, $sleep] = $this->fakeClock();
        $sweeps = 0;

        $result = PopSweep::run(
            timeoutSeconds: 0,
            queues: ['default'],
            probe: function (string $queue) use (&$sweeps): ?QueuedJob {
                $sweeps++;

                return $sweeps >= 3 ? $this->job($queue) : null;
            },
            probeCanBlock: false,
            waitCapSeconds: 1.0,
            sleep: $sleep,
            clock: $clock,
        );

        self::assertNotNull($result);
        self::assertSame(3, $sweeps);
    }

    /**
     * No busy-spin: a wholly empty, 5-second-deadline run at a 1-second
     * pace does exactly 5 sleeps — one per second of budget — and
     * exactly 6 immediate probes (the loop's own top-of-iteration probe
     * runs once more than it sleeps, since the very last iteration's
     * probe is what the deadline check right after it stops) — not an
     * unbounded or runaway number of either.
     */
    public function test_an_empty_bounded_run_does_not_busy_spin(): void
    {
        [$clock, $realSleep] = $this->fakeClock();
        $sleepCalls = 0;
        $probeCalls = 0;

        $result = PopSweep::run(
            timeoutSeconds: 5,
            queues: ['default'],
            probe: function () use (&$probeCalls): ?QueuedJob {
                $probeCalls++;

                return null;
            },
            probeCanBlock: false,
            waitCapSeconds: 1.0,
            sleep: function (float $seconds) use (&$sleepCalls, $realSleep): void {
                $sleepCalls++;
                $realSleep($seconds);
            },
            clock: $clock,
        );

        self::assertNull($result);
        self::assertSame(5, $sleepCalls);
        self::assertSame(6, $probeCalls);
    }

    // --- self-validation: PopSweep does not trust a caller ---------------

    public function test_run_validates_timeout_and_queues_itself_even_when_called_directly(): void
    {
        $this->expectException(InvalidPopTimeoutException::class);

        PopSweep::run(
            timeoutSeconds: -1,
            queues: ['default'],
            probe: static fn (): ?QueuedJob => self::fail('probe() must never run against an invalid timeout.'),
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static fn (): mixed => self::fail('sleep() must never run against an invalid timeout.'),
        );
    }

    public function test_run_validates_the_queue_list_itself_even_when_called_directly(): void
    {
        $this->expectException(InvalidQueueNameException::class);

        PopSweep::run(
            timeoutSeconds: 5,
            queues: ['default', 'default'],
            probe: static fn (): ?QueuedJob => self::fail('probe() must never run against an invalid queue list.'),
            probeCanBlock: true,
            waitCapSeconds: 1.0,
            sleep: static fn (): mixed => self::fail('sleep() must never run against an invalid queue list.'),
        );
    }

    /**
     * Each rejected value with the label the message has to report it as.
     * The three non-finite ones are why the labels are pinned at all: a
     * float cast is what renders them today, and a message that read
     * `got .` or `got -1.#IND.` on some other build would be useless for
     * the one input this check exists to catch.
     *
     * @return list<array{float, string}>
     */
    public static function invalidWaitCaps(): array
    {
        return [
            'zero' => [0.0, '0.0'],
            'negative' => [-1.0, '-1.0'],
            'NAN' => [NAN, 'NAN'],
            'INF' => [INF, 'INF'],
            '-INF' => [-INF, '-INF'],
        ];
    }

    /**
     * A non-positive or non-finite waitCapSeconds would otherwise make
     * PopSweep's own liveness guarantee — an unbounded (timeoutSeconds:
     * 0) pop() blocks until something's available, never returns null on
     * its own — silently false: every bound it computes (the per-queue
     * wait, or the paced sleep between sweeps) is capped by this value,
     * so a non-positive one is immediately satisfied and a non-finite
     * one can never be. Rejected before any probe/sleep call, on both
     * the probeCanBlock: true and probeCanBlock: false shapes.
     */
    #[DataProvider('invalidWaitCaps')]
    public function test_a_non_positive_or_non_finite_wait_cap_is_rejected_for_a_blocking_backend(float $waitCapSeconds, string $reported): void
    {
        $this->expectException(InvalidPopSweepConfigurationException::class);
        $this->expectExceptionMessage("got {$reported}.");

        PopSweep::run(
            timeoutSeconds: 5,
            queues: ['default'],
            probe: static fn (): ?QueuedJob => self::fail('probe() must never run against an invalid wait cap.'),
            probeCanBlock: true,
            waitCapSeconds: $waitCapSeconds,
            sleep: static fn (): mixed => self::fail('sleep() must never run against an invalid wait cap.'),
        );
    }

    #[DataProvider('invalidWaitCaps')]
    public function test_a_non_positive_or_non_finite_wait_cap_is_rejected_for_a_non_blocking_backend(float $waitCapSeconds, string $reported): void
    {
        $this->expectException(InvalidPopSweepConfigurationException::class);
        $this->expectExceptionMessage("got {$reported}.");

        PopSweep::run(
            timeoutSeconds: 0,
            queues: ['default'],
            probe: static fn (): ?QueuedJob => null,
            probeCanBlock: false,
            waitCapSeconds: $waitCapSeconds,
            sleep: static fn (): mixed => self::fail('sleep() must never run against an invalid wait cap — the busy-loop this guards against would otherwise call it forever.'),
        );
    }

    /**
     * The concrete failure mode a non-positive wait cap would otherwise
     * cause, if it went unvalidated: an unbounded pop() against a
     * non-blocking backend would compute sleepFor <= 0.0 on its very
     * first sweep and return null immediately — the exact opposite of
     * "block until found." Confirmed the validation is what prevents
     * this, not assumed from the check alone.
     */
    public function test_an_unvalidated_zero_wait_cap_would_have_made_an_unbounded_pop_return_null_immediately(): void
    {
        $probeCalls = 0;

        try {
            PopSweep::run(
                timeoutSeconds: 0,
                queues: ['default'],
                probe: function () use (&$probeCalls): ?QueuedJob {
                    $probeCalls++;

                    return null;
                },
                probeCanBlock: false,
                waitCapSeconds: 0.0,
                sleep: static function (): void {
                },
            );
            self::fail('Expected InvalidPopSweepConfigurationException.');
        } catch (InvalidPopSweepConfigurationException) {
            // Expected — and specifically what stops the "return null
            // immediately despite an unbounded request" failure mode:
            // the exception fires before the loop's first sweep even
            // runs.
            self::assertSame(0, $probeCalls);
        }
    }
}
