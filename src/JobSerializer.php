<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\Support\WireValue;
use ReflectionClass;
use Throwable;

/**
 * Converts a live object to plain {class, args} data and back — the
 * identical shape Kinetis\Validation\Hydrator already uses for constraint
 * descriptors (captured as plain data, reconstructed via
 * `new $class(...$args)`, never PHP's native serialize()/unserialize(),
 * which is a real, known injection-vector class against untrusted data
 * pulled back out of a queue later).
 *
 * Typed against plain `object`, not `Job` specifically — the reflection
 * mechanism itself has nothing Job-specific about it (Job is a marker
 * interface adding no behavior), and QueuedListenerInvoker reuses this
 * exact method to serialize an *event*, not a Job, when deferring a
 * ShouldQueue listener onto the queue.
 *
 * serialize() reads each constructor parameter's value off a same-named
 * property via reflection rather than requiring the caller to pass the
 * data separately — an object is constructed normally
 * (`new SendWelcomeEmail($email, $name)`) exactly like any other DTO in
 * this codebase, with nothing serialization-specific about how it's built.
 *
 * Every argument value, not just its parameter, must also survive the
 * round trip to a genuinely different process later (RedisQueue/
 * SqlQueue/SqsQueue/RabbitMqQueue each JSON-encode {class, args} to store
 * it; SyncQueue and the in-memory test fixture hold the same {class,
 * args} shape without ever touching JSON, but must still behave the
 * same way regardless) — see Kinetis\Queue\Support\WireValue for exactly
 * what's portable and why.
 */
final class JobSerializer
{
    /**
     * The placeholder logged in place of a #[Sensitive] argument.
     */
    public const string REDACTED = '[redacted]';

    /**
     * @return array{class: class-string, args: array<string, mixed>}
     */
    public static function serialize(object $job): array
    {
        $reflection = new ReflectionClass($job);
        $constructor = $reflection->getConstructor();

        /** @var class-string $class */
        $class = $reflection->getName();

        $sensitive = self::sensitiveParameters($class);
        $args = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (!$reflection->hasProperty($name)) {
                throw UnserializableJobException::forParameter($class, $name);
            }

            $value = $reflection->getProperty($name)->getValue($job);

            try {
                $args[$name] = WireValue::normalize($value, $class, $name);
            } catch (UnserializableJobException $e) {
                // A rejection anywhere within a #[Sensitive] argument's
                // own value never surfaces the real nested path — the
                // rejection reason WireValue::normalize() built may
                // itself have walked through application data (a map
                // key, most concretely) that this exact attribute exists
                // to keep out of a log line in the first place. Naming
                // the argument is still useful; naming what's inside it
                // is exactly what #[Sensitive] promises not to do.
                throw in_array($name, $sensitive, true)
                    ? UnserializableJobException::forSensitiveValue($class, $name)
                    : $e;
            }
        }

        return ['class' => $class, 'args' => $args];
    }

    /**
     * The general reconstruction path — used both for a real Job
     * (QueueWorker prefers deserializeJob() instead, see below, but this
     * still works for one directly) and for a plain event
     * (Kinetis\Queue\InvokeListenerJob reconstructs the event
     * QueuedListenerInvoker deferred, and an event is deliberately never
     * required to implement Job).
     *
     * The queue is trusted infrastructure on the same tier as the
     * database, not an input boundary: protect write access to the
     * backend itself, not this method, against a hostile payload. What
     * this method does defend against is schema drift — a rolling
     * deployment where the class that pushed a payload and the class
     * popping it disagree on the constructor's own shape — turning that
     * into one stable JobReconstructionException naming the class,
     * argument, and location involved, instead of whatever raw
     * Error/TypeError PHP's own `new $class(...)` call happens to throw
     * with no payload context at all.
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
     * The path QueueWorker actually uses — identical to deserialize(),
     * with one more check: $class must implement Job. A worker
     * reconstructing what's meant to be run as a job, from a payload
     * that (through drift, or a hand-crafted queue row) no longer names
     * one, fails clearly here rather than passing a non-Job into
     * JobInvoker::invoke(), which assumes handle() exists at all.
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
     * The shared reconstruction body deserialize()/deserializeJob() both
     * run once $class itself is confirmed to exist (and, for
     * deserializeJob(), to implement Job): validates $args names against
     * the constructor's real, current parameter list (every required
     * parameter present, no unrecognized extra key — either is schema
     * drift, not a hostile payload), restores each value through
     * WireValue::restore() (reversing normalize()'s enum/datetime
     * tagging), then constructs — wrapping any throwable the constructor
     * itself raises, rather than letting it escape uncaught.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    private static function reconstruct(string $class, array $args): object
    {
        $constructor = new ReflectionClass($class)->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        $names = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $names[$name] = true;

            if (!array_key_exists($name, $args) && !$parameter->isOptional()) {
                throw JobReconstructionException::missingRequiredArgument($class, $name);
            }
        }

        foreach (array_keys($args) as $key) {
            if (!isset($names[$key])) {
                throw JobReconstructionException::unknownArgument($class, $key);
            }
        }

        $restored = [];

        foreach ($args as $key => $value) {
            $restored[$key] = WireValue::restore($value, $class, $key);
        }

        try {
            return new $class(...$restored);
        } catch (Throwable $e) {
            throw JobReconstructionException::constructionFailed($class, $e);
        }
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function sensitiveParameters(string $class): array
    {
        $constructor = new ReflectionClass($class)->getConstructor();
        $names = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(Sensitive::class) !== []) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }
}
