<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * A job's handle() method has a parameter QueueWorker can't autowire —
 * either untyped or typed as a builtin scalar. Every handle() parameter
 * must be class-typed; a job's own constructor is the only place scalar
 * data comes from.
 */
final class UnresolvableJobParameterException extends RuntimeException
{
    public static function forParameter(string $class, string $parameter): self
    {
        return new self("Cannot resolve parameter \"\${$parameter}\" of \"{$class}::handle()\" — every handle() parameter must be class-typed so it can be autowired.");
    }
}
