<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use Kinetis\Queue\JobSettlement;
use RuntimeException;

/**
 * A settlement named a delivery the backend no longer holds — already
 * settled through another call, or reclaimed once its reservation
 * expired. Nothing was written.
 *
 * A backend raises this only where it can actually tell one delivery
 * from another; one that cannot says so in its own docblock rather than
 * settling by job identity. QueueWorker treats it as a lost delivery,
 * not a job failure.
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
