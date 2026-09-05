<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * PopSweep::run()'s own liveness guarantee — an unbounded (timeoutSeconds:
 * 0) pop() blocks until something's available, never returns null on its
 * own — depends entirely on $waitCapSeconds being a real, positive,
 * finite bound: it's what a probeCanBlock: true call bounds every
 * per-queue wait by, and what a probeCanBlock: false call paces sleep()
 * calls by. A non-positive or non-finite value would make that bound
 * immediately satisfied (or never satisfiable), silently turning
 * "block until found" into "return null on the very first sweep" or an
 * infinite busy-loop — rejected here instead of trusted to a caller
 * that might not be one of this package's own backends.
 */
final class InvalidPopSweepConfigurationException extends InvalidArgumentException
{
    public static function waitCapMustBePositiveAndFinite(float $waitCapSeconds): self
    {
        return new self(
            "PopSweep::run()'s \$waitCapSeconds must be a positive, finite number of seconds, got "
            . self::describe($waitCapSeconds) . '.',
        );
    }

    /**
     * Fixed labels for the non-finite values rather than a float cast
     * that varies by build; a finite value renders as a float (`0.0`,
     * not `0`).
     */
    private static function describe(float $waitCapSeconds): string
    {
        if (is_nan($waitCapSeconds)) {
            return 'NAN';
        }

        if (is_infinite($waitCapSeconds)) {
            return $waitCapSeconds > 0 ? 'INF' : '-INF';
        }

        return var_export($waitCapSeconds, true);
    }
}
