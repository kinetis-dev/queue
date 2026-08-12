<?php

declare(strict_types=1);

namespace Kinetis\Queue;

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
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public static function deserialize(string $class, array $args): object
    {
        return new $class(...$args);
    }
}
