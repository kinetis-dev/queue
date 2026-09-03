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
 *
 * Also thrown when a constructor argument's own value — not just its
 * parameter — can't survive that round trip, per
 * Kinetis\Queue\Support\WireValue's portable wire-value contract: a
 * resource, a closure, an unsupported object, NAN/INF, invalid UTF-8, or
 * a sparse/mixed-key array, at any depth within the argument.
 */
final class UnserializableJobException extends RuntimeException
{
    public static function forParameter(string $class, string $parameter): self
    {
        return new self("Cannot serialize \"{$class}\": no property matching constructor parameter \"\${$parameter}\" — every constructor parameter must correspond to a same-named property.");
    }

    /**
     * $reason describes the value's *shape*, never its content — the
     * value itself may be sensitive, so only its type/path ever reaches
     * this message.
     */
    public static function forUnsupportedValue(string $class, string $path, string $reason): self
    {
        return new self("Cannot serialize \"{$class}\": the value at \"{$path}\" is {$reason}, which cannot be represented as portable queue data.");
    }

    /**
     * The identical rejection forUnsupportedValue() describes, but for a
     * #[Sensitive]-marked argument — deliberately naming only the
     * argument itself, never any nested path within it. A regular
     * argument's own path can point through application data (a map
     * key, most concretely — see WireValue's own docblock), which is
     * exactly what #[Sensitive] exists to keep out of a log line.
     */
    public static function forSensitiveValue(string $class, string $parameter): self
    {
        return new self("Cannot serialize \"{$class}\": the #[Sensitive] argument \"\${$parameter}\" holds a value that cannot be represented as portable queue data.");
    }
}
