<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Exception\UnserializableJobException;
use ReflectionClass;

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
        $args = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (!$reflection->hasProperty($name)) {
                throw UnserializableJobException::forParameter($reflection->getName(), $name);
            }

            $args[$name] = $reflection->getProperty($name)->getValue($job);
        }

        /** @var class-string $class */
        $class = $reflection->getName();

        return ['class' => $class, 'args' => $args];
    }

    /**
     * Constructs whatever class name the queue payload carries, with the
     * payload's own arguments — so anyone who can write to the queue
     * backend can instantiate any class the worker can autoload, with
     * constructor arguments of their choosing. The queue is trusted
     * infrastructure on the same tier as the database, not an input
     * boundary: protect write access to the backend itself rather than
     * expecting this method to reject a hostile payload.
     *
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public static function deserialize(string $class, array $args): object
    {
        return new $class(...$args);
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
