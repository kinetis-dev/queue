<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * A stored payload names a class or arguments the current code no longer
 * matches — schema drift between the process that pushed the job and the
 * one popping it. Distinct from MalformedQueuedJobDataException, which is
 * corrupted storage rather than a signature that moved.
 */
final class JobReconstructionException extends RuntimeException
{
    public static function classDoesNotExist(string $class): self
    {
        return new self("Cannot reconstruct \"{$class}\": that class no longer exists.");
    }

    public static function notAJob(string $class): self
    {
        return new self("Cannot reconstruct \"{$class}\" as a job: it does not implement Kinetis\\Queue\\Job.");
    }

    public static function missingRequiredArgument(string $class, string $parameter): self
    {
        return new self("Cannot reconstruct \"{$class}\": its constructor requires \"\${$parameter}\", which the stored payload does not carry.");
    }

    public static function unknownArgument(string $class, string $parameter): self
    {
        return new self("Cannot reconstruct \"{$class}\": the stored payload carries \"\${$parameter}\", which matches no constructor parameter.");
    }

    /**
     * The stored value for $parameter cannot become the type that
     * parameter declares — an enum case that no longer exists, an
     * unparseable date string.
     *
     * Names the class and the argument and nothing else, with no
     * previous exception: PHP's own "not a valid backing value" and
     * date-parse messages quote the value they were handed, and that
     * value can be a #[Sensitive] one on its way into a log line.
     */
    public static function unrestorableArgument(string $class, string $parameter): self
    {
        return new self("Cannot reconstruct \"{$class}\": the stored value for \"\${$parameter}\" does not match the type that parameter declares.");
    }

    public static function constructionFailed(string $class, Throwable $previous): self
    {
        return new self("Cannot reconstruct \"{$class}\": its constructor threw — {$previous->getMessage()}", previous: $previous);
    }

    /**
     * The same failure for a class whose supplied arguments include a
     * #[Sensitive] one. A constructor validating what it was handed
     * routinely quotes it, and both the message and the chained cause
     * reach a log through QueueWorker, so neither is carried: the class
     * name is the whole diagnostic.
     */
    public static function constructionFailedWithSensitiveArgument(string $class): self
    {
        return new self(
            "Cannot reconstruct \"{$class}\": its constructor threw. The cause is withheld because an argument "
            . 'is marked #[Sensitive].',
        );
    }
}
