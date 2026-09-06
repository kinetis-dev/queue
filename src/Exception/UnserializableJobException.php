<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * A job cannot be turned into portable queue data — a constructor
 * parameter with no matching property, an argument value that has no
 * JSON representation, or one nested past the traversal bound. Raised at
 * push() time so the failure happens at the call site rather than in a
 * worker process later.
 */
final class UnserializableJobException extends RuntimeException
{
    public static function forParameter(string $class, string $parameter): self
    {
        return new self("Cannot serialize \"{$class}\": no property matching constructor parameter \"\${$parameter}\" — every constructor parameter must correspond to a same-named property.");
    }

    /**
     * $path locates the value inside the argument tree (`items[3].{0}`)
     * and $reason names its type. Neither carries the value itself: a
     * list index and a map entry's ordinal position describe the
     * payload's shape, where a map key is application data.
     */
    public static function forUnsupportedValue(string $class, string $path, string $reason): self
    {
        return new self("Cannot serialize \"{$class}\": the value at \"{$path}\" is {$reason}, which cannot be represented as portable queue data.");
    }

    /**
     * The argument nests arrays past the traversal bound — an absurd
     * structure, or a self-referential one, which has no depth to
     * measure at all.
     */
    public static function forExcessiveNesting(string $class, string $path, int $maxDepth): self
    {
        return new self("Cannot serialize \"{$class}\": the value at \"{$path}\" nests arrays more than {$maxDepth} levels deep, which portable queue data does not carry.");
    }

    /**
     * A rejection inside a #[Sensitive] argument names only the argument:
     * the path itself walks application data that attribute exists to
     * keep out of a log line.
     */
    public static function forSensitiveValue(string $class, string $parameter): self
    {
        return new self("Cannot serialize \"{$class}\": the #[Sensitive] argument \"\${$parameter}\" holds a value that cannot be represented as portable queue data.");
    }
}
