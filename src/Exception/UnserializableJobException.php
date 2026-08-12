<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * A Job's constructor parameter has no same-named property JobSerializer
 * can read back — most commonly a parameter that's computed or
 * transformed in the constructor body rather than stored directly. Thrown
 * at push() time, not silently ignored, since a job that can't be
 * serialized can't survive the round trip to a worker process at all.
 */
final class UnserializableJobException extends RuntimeException
{
    public static function forParameter(string $class, string $parameter): self
    {
        return new self("Cannot serialize \"{$class}\": no property matching constructor parameter \"\${$parameter}\" — every constructor parameter must correspond to a same-named property.");
    }
}
