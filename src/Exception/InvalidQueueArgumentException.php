<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * A caller passed an argument no backend can act on — a malformed queue
 * name, a negative delay/timeout, an out-of-range attempt count.
 *
 * One type for every such mistake rather than one per argument: they are
 * all the same class of failure (a programming error at the call site,
 * fixed by changing the call), and none of them is something a caller
 * catches to recover from.
 *
 * A queue name is validated against the narrowest grammar the four real
 * backends agree on — Amazon SQS's standard-queue rule, up to 80
 * characters of letters, digits, hyphens and underscores. A portable
 * name is one valid on all four, not only on whichever backend happens
 * to be configured.
 */
final class InvalidQueueArgumentException extends InvalidArgumentException
{
    public static function emptyQueueName(): self
    {
        return new self('A queue name must not be an empty string.');
    }

    public static function malformedQueueName(string $queue, int $maxLength): self
    {
        return new self(
            "Queue name \"{$queue}\" is not valid — it must be 1-{$maxLength} characters, containing only "
            . 'letters, digits, hyphens, and underscores.',
        );
    }

    public static function malformedQueueNamePrefix(string $prefix, int $maxLength): self
    {
        return new self(
            "Queue name prefix \"{$prefix}\" is not valid — like a queue name itself, it must be 1-{$maxLength} "
            . 'characters, containing only letters, digits, hyphens, and underscores.',
        );
    }

    public static function resolvedNameTooLong(string $resolvedName, int $maxLength): self
    {
        return new self(
            "Resolved queue name \"{$resolvedName}\" is " . \strlen($resolvedName) . " characters, over the "
            . "{$maxLength}-character limit — the prefix combined with this queue name is too long.",
        );
    }

    public static function duplicateQueueName(string $queue): self
    {
        return new self(
            "The queue \"{$queue}\" appears more than once in the same pop() \$queues list — priority is already "
            . 'expressed once by list order.',
        );
    }

    public static function negativePopTimeout(int $timeoutSeconds): self
    {
        return new self(
            "QueueInterface::pop()'s \$timeoutSeconds must be 0 (block with no deadline) or positive "
            . "(block for up to that many seconds), got {$timeoutSeconds}.",
        );
    }

    public static function negativeDelaySeconds(int $delaySeconds): self
    {
        return new self(
            "QueueInterface::push()'s \$delaySeconds must be 0 (push immediately) or positive, got {$delaySeconds}.",
        );
    }

    public static function negativeMaxAttempts(int $maxAttempts): self
    {
        return new self(
            "\$maxAttempts must be null (defer to the worker's own default) or non-negative, got {$maxAttempts}.",
        );
    }

    public static function attemptsBelowOne(int $attempts): self
    {
        return new self(
            "QueuedJob::\$attempts is 1-indexed and must be 1 or greater, got {$attempts}.",
        );
    }
}
