<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use InvalidArgumentException;

/**
 * A queue name reaches every backend's own storage layer directly — a
 * Redis key segment, a SQL column value, an AMQP routing key, an SQS
 * queue-name lookup — so an empty string, a malformed one, or a name
 * repeated in one pop() $queues list is never a backend-specific
 * accident to discover later; it's rejected once, here, before any
 * backend ever sees it.
 */
final class InvalidQueueNameException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('A queue name must not be an empty string.');
    }

    /**
     * $queue failed QueueContract::VALID_NAME_PATTERN — the narrowest
     * constraint any of the four backends actually imposes (Amazon SQS's
     * own standard-queue naming rule): up to $maxLength characters,
     * alphanumeric plus hyphen/underscore only.
     */
    public static function malformed(string $queue, int $maxLength): self
    {
        return new self(
            "Queue name \"{$queue}\" is not a valid logical queue name — it must be 1-{$maxLength} characters, "
            . 'containing only letters, digits, hyphens, and underscores (Amazon SQS\'s own real naming rule, '
            . 'adopted as the one shared, cross-backend-safe grammar).',
        );
    }

    public static function malformedPrefix(string $prefix, int $maxLength): self
    {
        return new self(
            "Queue name prefix \"{$prefix}\" is not valid — like a queue name itself, it must be 1-{$maxLength} "
            . 'characters, containing only letters, digits, hyphens, and underscores.',
        );
    }

    public static function duplicate(string $queue): self
    {
        return new self(
            "The queue \"{$queue}\" appears more than once in the same pop() \$queues list — a repeated name "
            . 'is always a caller mistake (a copy-paste error, most likely), never a meaningful configuration: '
            . 'priority is already expressed once by list order.',
        );
    }

    /**
     * A backend that resolves a caller-supplied queue name against a
     * real, independently length-limited backend name (SqsQueue's own
     * $queueNamePrefix . $queue, checked against Amazon SQS's real
     * 80-character cap on the name it actually sends) throws this once
     * both halves are individually valid but their combination is not —
     * a case QueueContract's own per-string checks can't catch on
     * either half alone.
     */
    public static function resolvedNameTooLong(string $resolvedName, int $maxLength): self
    {
        return new self(
            "Resolved queue name \"{$resolvedName}\" is " . \strlen($resolvedName) . ' characters, over the '
            . "{$maxLength}-character limit — the queue name prefix combined with this queue name is too long.",
        );
    }
}
