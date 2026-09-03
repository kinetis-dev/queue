<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * JobSerializer::deserialize()/deserializeJob() couldn't rebuild an
 * object from a queue payload — schema drift (a job/event class renamed
 * a constructor parameter, or was deployed with a different signature
 * than the worker still running old code expects) is the realistic
 * cause, not a hostile payload: the queue is trusted infrastructure on
 * the database's own tier, not an input boundary, the same threat model
 * JobSerializer::deserialize()'s own docblock already states. This
 * exception exists so that drift becomes one stable, catchable type
 * naming the class/argument/location involved, instead of whatever raw
 * Error/TypeError PHP's own constructor call happens to throw with no
 * payload context at all.
 */
final class JobReconstructionException extends RuntimeException
{
    public static function classDoesNotExist(string $class): self
    {
        return new self("Cannot reconstruct \"{$class}\": that class no longer exists — a real, if unlikely, sign of schema drift between the process that pushed this job and the one popping it.");
    }

    public static function notAJob(string $class): self
    {
        return new self("Cannot reconstruct \"{$class}\" as a job: it does not implement Kinetis\\Queue\\Job.");
    }

    public static function missingRequiredArgument(string $class, string $parameter): self
    {
        return new self("Cannot reconstruct \"{$class}\": its constructor requires \"\${$parameter}\", which the stored payload does not carry — the class's constructor signature has likely changed since this payload was pushed.");
    }

    public static function unknownArgument(string $class, string $parameter): self
    {
        return new self("Cannot reconstruct \"{$class}\": the stored payload carries \"\${$parameter}\", which no longer matches any constructor parameter — the class's constructor signature has likely changed since this payload was pushed.");
    }

    /**
     * $reason describes the tag's own shape, never any value it might
     * carry.
     */
    public static function invalidWireValue(string $class, string $path, string $reason): self
    {
        return new self("Cannot reconstruct \"{$class}\": the value at \"{$path}\" is {$reason}.");
    }

    public static function constructionFailed(string $class, Throwable $previous): self
    {
        return new self("Cannot reconstruct \"{$class}\": its constructor threw — {$previous->getMessage()}", previous: $previous);
    }
}
