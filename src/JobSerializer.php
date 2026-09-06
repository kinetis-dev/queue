<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use BackedEnum;
use DateTimeImmutable;
use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Converts a live object to plain {class, args} data and back — the same
 * shape Kinetis\Validation\Hydrator uses for constraint descriptors
 * (captured as plain data, reconstructed via `new $class(...$args)`,
 * never PHP's native serialize()/unserialize(), a known injection vector
 * against data pulled back out of a queue later).
 *
 * Typed against plain `object`, not `Job` — the reflection has nothing
 * Job-specific about it, and QueuedListenerInvoker serializes an *event*
 * through this same method when deferring a ShouldQueue listener.
 *
 * serialize() reads each constructor parameter's value off a same-named
 * property, so a job is constructed normally
 * (`new SendWelcomeEmail($email, $name)`) with nothing
 * serialization-specific about how it is built.
 *
 * **What an argument may hold.** Job arguments cross a real process
 * boundary as JSON, so the wire shape is JSON's: null, bool, int, a
 * finite float, a valid-UTF-8 string, and arrays of those (a dense
 * zero-based list, or a map whose keys are all strings), nested within
 * the bound below. Anything else is rejected at push() time rather than
 * discovered as a worker-side crash — SyncQueue enforces the same rule,
 * so local development fails the same way a durable backend would.
 *
 * A BackedEnum case and a DateTimeImmutable are accepted as a top-level
 * argument, written as their scalar form and restored from the
 * constructor parameter's declared type. That type is the only thing
 * that can identify them on the way back, so serialize() accepts one
 * only where the type states unambiguously what to restore: a single
 * ReflectionNamedType naming that exact enum class, or exactly
 * DateTimeImmutable. A union, an intersection, `mixed`, no type at all,
 * an interface and a supertype are all rejected at push() — each would
 * leave the worker with a bare string or int and nothing saying what it
 * was meant to become. The same values nested inside an array are
 * rejected for that same reason: an array carries no declared type.
 *
 * Array nesting is bounded at MAX_DEPTH levels. A self-referential
 * array has no depth at all, and traversing one would exhaust a
 * worker's memory; the bound turns both that and a merely absurd
 * structure into an ordinary push()-time rejection.
 */
final class JobSerializer
{
    /**
     * The placeholder logged in place of a #[Sensitive] argument.
     */
    public const string REDACTED = '[redacted]';

    /**
     * How many levels of array nesting an argument may carry. Deep
     * enough that no payload built to cross a queue meets it, shallow
     * enough that a self-referential one fails at once rather than
     * exhausting the process.
     */
    private const int MAX_DEPTH = 32;

    /**
     * @return array{class: class-string, args: array<string, mixed>}
     */
    public static function serialize(object $job): array
    {
        $reflection = new ReflectionClass($job);

        /** @var class-string $class */
        $class = $reflection->getName();

        $sensitive = self::sensitiveParameters($class);
        $args = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (!$reflection->hasProperty($name)) {
                throw UnserializableJobException::forParameter($class, $name);
            }

            $value = $reflection->getProperty($name)->getValue($job);

            try {
                $args[$name] = self::toWire($value, $class, $name, $parameter, depth: 0);
            } catch (UnserializableJobException $e) {
                // The rejection path walks application data — a map key
                // above all — which is exactly what #[Sensitive] exists
                // to keep out of a log line. Naming the argument is
                // still useful; naming what is inside it is not.
                throw \in_array($name, $sensitive, true)
                    ? UnserializableJobException::forSensitiveValue($class, $name)
                    : $e;
            }
        }

        return ['class' => $class, 'args' => $args];
    }

    /**
     * The general reconstruction path — a real Job (QueueWorker uses
     * deserializeJob() instead) or a plain event, which is never
     * required to implement Job.
     *
     * The queue is trusted infrastructure on the same tier as the
     * database, not an input boundary: protect write access to the
     * backend rather than expecting this method to reject a hostile
     * payload. What it does defend against is schema drift — a rolling
     * deployment where the pushing and popping processes disagree on a
     * constructor — reported as one JobReconstructionException naming the
     * class and argument instead of whatever raw Error `new $class(...)`
     * happens to throw.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public static function deserialize(string $class, array $args): object
    {
        self::assertClassExists($class);

        return self::reconstruct($class, $args);
    }

    /**
     * deserialize() plus the one check a worker needs: $class must
     * implement Job, so a drifted payload fails here rather than inside
     * JobInvoker::invoke(), which assumes handle() exists.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public static function deserializeJob(string $class, array $args): Job
    {
        self::assertClassExists($class);

        if (!is_a($class, Job::class, true)) {
            throw JobReconstructionException::notAJob($class);
        }

        /** @var Job */
        return self::reconstruct($class, $args);
    }

    /**
     * Returns $args with every value whose constructor parameter carries
     * #[Sensitive] replaced by REDACTED, for logging a job that failed.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function redact(string $class, array $args): array
    {
        // Fails closed: a class that no longer loads — itself a reason a
        // job fails — leaves no way to tell which arguments are sensitive,
        // so every one of them is redacted. Keys survive either way, so
        // the entry still carries the shape of the payload.
        if (!class_exists($class)) {
            return array_fill_keys(array_keys($args), self::REDACTED);
        }

        foreach (self::sensitiveParameters($class) as $name) {
            if (array_key_exists($name, $args)) {
                $args[$name] = self::REDACTED;
            }
        }

        return $args;
    }

    /**
     * @param class-string $class
     */
    private static function assertClassExists(string $class): void
    {
        if (!class_exists($class)) {
            throw JobReconstructionException::classDoesNotExist($class);
        }
    }

    /**
     * Validates $args against the constructor's current parameter list
     * (every required parameter present, no unrecognized key — either is
     * schema drift), restores each value to the type its parameter
     * declares, then constructs.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    private static function reconstruct(string $class, array $args): object
    {
        $parameters = [];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $parameters[$name] = $parameter;

            if (!array_key_exists($name, $args) && !$parameter->isOptional()) {
                throw JobReconstructionException::missingRequiredArgument($class, $name);
            }
        }

        $restored = [];

        foreach ($args as $name => $value) {
            if (!isset($parameters[$name])) {
                throw JobReconstructionException::unknownArgument($class, $name);
            }

            try {
                $restored[$name] = self::fromWire($value, $parameters[$name]);
            } catch (Throwable) {
                // The cause is dropped rather than chained: PHP's own
                // "not a valid backing value" and date-parse messages
                // quote the stored value, which may be a #[Sensitive]
                // one, and the class and parameter already say
                // everything a reader can act on.
                throw JobReconstructionException::unrestorableArgument($class, $name);
            }
        }

        try {
            return new $class(...$restored);
        } catch (Throwable $e) {
            // A constructor is free to quote what it was handed, and
            // QueueWorker logs both the message and the chained cause of
            // this exception. Where one of the supplied arguments is
            // #[Sensitive], neither is carried; every other class keeps
            // the cause, which is the useful half of the report.
            throw self::suppliesSensitiveArgument($class, $args)
                ? JobReconstructionException::constructionFailedWithSensitiveArgument($class)
                : JobReconstructionException::constructionFailed($class, $e);
        }
    }

    /**
     * Whether $args supplies a value for a parameter marked #[Sensitive]
     * — the parameters actually handed to the constructor, not every
     * marked one the class declares.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    private static function suppliesSensitiveArgument(string $class, array $args): bool
    {
        foreach (self::sensitiveParameters($class) as $name) {
            if (array_key_exists($name, $args)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $path locates a rejected value inside the argument tree
     * (`items[3].{0}`) for the exception message; no value ever reaches
     * it. $parameter is the declaring constructor parameter for a
     * top-level argument and null for anything inside an array — the
     * one position where a declared type can restore an enum or a date
     * against the one where nothing can.
     */
    private static function toWire(mixed $value, string $class, string $path, ?ReflectionParameter $parameter, int $depth): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw UnserializableJobException::forUnsupportedValue($class, $path, 'a non-finite float');
            }

            return $value;
        }

        if (\is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw UnserializableJobException::forUnsupportedValue($class, $path, 'not valid UTF-8');
            }

            return $value;
        }

        if (\is_array($value)) {
            return self::arrayToWire($value, $class, $path, $depth);
        }

        // A resource or any other non-object lands here with no ::class
        // to read, so it is answered before the class comparisons below.
        if (!\is_object($value)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a value of type ' . get_debug_type($value));
        }

        if ($value instanceof BackedEnum && self::declaresExactly($parameter, $value::class)) {
            return $value->value;
        }

        // Exactly DateTimeImmutable, not a subclass: a subclass's own
        // state has no representation here, and restoring one would
        // silently drop it. Microseconds, not RFC3339_EXTENDED's
        // milliseconds — a truncated timestamp is a changed value.
        if ($value::class === DateTimeImmutable::class && self::declaresExactly($parameter, DateTimeImmutable::class)) {
            return $value->format('Y-m-d\\TH:i:s.uP');
        }

        throw UnserializableJobException::forUnsupportedValue($class, $path, match (true) {
            $parameter === null && ($value instanceof BackedEnum || $value instanceof DateTimeImmutable)
                => 'an enum case or date nested inside an array, where no parameter type can restore it',
            $value instanceof BackedEnum
                => 'an enum case whose parameter does not declare that exact enum class, so nothing states what to restore it to',
            $value::class === DateTimeImmutable::class
                => 'a date whose parameter does not declare exactly DateTimeImmutable, so nothing states what to restore it to',
            $value instanceof DateTimeImmutable
                => 'a DateTimeImmutable subclass, whose own state has no representation here',
            default => 'an instance of ' . get_debug_type($value),
        });
    }

    /**
     * Whether $parameter declares $class and nothing else. One
     * ReflectionNamedType naming that exact class is the only
     * declaration fromWire() can act on; a union, an intersection,
     * `mixed`, an absent type, an interface and a supertype all leave
     * the stored scalar with nothing saying what it was. A nullable
     * `?Foo` is a ReflectionNamedType naming Foo and qualifies.
     */
    private static function declaresExactly(?ReflectionParameter $parameter, string $class): bool
    {
        $type = $parameter?->getType();

        return $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && $type->getName() === $class;
    }

    /**
     * A list keeps its indices; a map keeps its keys. Anything else — a
     * sparse or integer-keyed non-list array — has no lossless JSON
     * round trip, since PHP canonicalizes a numeric-looking key to int
     * and JSON object keys come back as strings.
     *
     * The depth bound is checked on entry, before any recursion, so a
     * self-referential array is answered by the first traversal to reach
     * MAX_DEPTH rather than by the memory limit.
     *
     * A list index is part of the payload's shape and is kept in $path.
     * A map key is application data — potentially a secret in its own
     * right — so a map entry is located by its ordinal position instead,
     * written `{2}`.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function arrayToWire(array $value, string $class, string $path, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw UnserializableJobException::forExcessiveNesting($class, $path, self::MAX_DEPTH);
        }

        $isList = array_is_list($value);
        $wire = [];
        $ordinal = 0;

        foreach ($value as $key => $item) {
            if (!$isList && !\is_string($key)) {
                throw UnserializableJobException::forUnsupportedValue($class, $path, 'a sparse or integer-keyed array');
            }

            $wire[$key] = self::toWire(
                $item,
                $class,
                $isList ? "{$path}[{$key}]" : sprintf('%s.{%d}', $path, $ordinal),
                null,
                $depth + 1,
            );

            ++$ordinal;
        }

        return $wire;
    }

    /**
     * Restores a stored scalar to whatever the parameter declares, for
     * the two non-JSON types serialize() accepts. Every other type
     * passes through: PHP's own coercion rules at construction time
     * already reject a genuine mismatch.
     */
    private static function fromWire(mixed $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($value === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $name = $type->getName();

        if (is_a($name, BackedEnum::class, true) && (\is_int($value) || \is_string($value))) {
            /** @var class-string<BackedEnum> $name */
            return $name::from($value);
        }

        if ($name === DateTimeImmutable::class && \is_string($value)) {
            return new DateTimeImmutable($value);
        }

        return $value;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function sensitiveParameters(string $class): array
    {
        $names = [];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(Sensitive::class) !== []) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }
}
