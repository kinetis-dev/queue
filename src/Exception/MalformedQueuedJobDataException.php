<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * A durable backend's stored message cannot be decoded into a QueuedJob:
 * invalid JSON, a missing or wrong-shaped field, a counter outside its
 * documented bounds.
 *
 * This is corruption in storage, not a caller mistake and not a job that
 * failed — QueueContract::settleIfMalformed() catches exactly this type
 * to settle the message permanently rather than replay it forever.
 *
 * The message names the field and the value's type, never the value
 * itself: stored job data is application payload and may be sensitive.
 */
final class MalformedQueuedJobDataException extends RuntimeException
{
    private static function corrupted(string $field, string $problem): self
    {
        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value {$problem} — the durable storage this "
            . 'job was read from may be corrupted.',
        );
    }

    public static function missingField(string $field): self
    {
        return self::corrupted($field, 'is missing entirely');
    }

    public static function invalidJson(string $field): self
    {
        return self::corrupted($field, 'is not valid JSON');
    }

    public static function invalidShape(string $field, mixed $raw): self
    {
        return self::corrupted($field, 'is a ' . get_debug_type($raw) . ', which is not the expected shape');
    }

    public static function notAnInteger(string $field, mixed $raw): self
    {
        return self::corrupted($field, 'is a ' . get_debug_type($raw) . ', not a representable integer');
    }

    public static function outOfBounds(string $field, string $requirement): self
    {
        return self::corrupted($field, "is out of bounds — {$requirement}");
    }

    public static function malformedValue(string $field, string $requirement): self
    {
        return self::corrupted($field, "is malformed — {$requirement}");
    }
}
