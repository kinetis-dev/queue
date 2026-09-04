<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\QueueInterface;
use RuntimeException;

/**
 * ClearableQueueInterface was asked for from the container while the
 * bound QueueInterface is a backend that does not declare it.
 *
 * Thrown at resolution time rather than at bootstrap: PackageBootstrap
 * binds the capability to whatever QueueInterface finally resolves to,
 * so which backend answers is settled only once the application's own
 * bootstrap.php has had its say — see that class's docblock.
 */
final class QueueNotClearableException extends RuntimeException
{
    public static function forBackend(QueueInterface $queue): self
    {
        return new self(self::describe($queue));
    }

    /**
     * The one wording of "this backend cannot clear," shared with
     * Kinetis\Queue\Console\ClearCommand, which reports the same fact
     * to an operator without an exception to throw at them.
     */
    public static function describe(QueueInterface $queue): string
    {
        return $queue::class . ' cannot discard waiting jobs: it does not implement ' . ClearableQueueInterface::class
            . '. Empty this backend\'s queues through its own infrastructure tooling instead.';
    }
}
