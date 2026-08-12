<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Queue\Exception\UnresolvableJobParameterException;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Invokes a Job's handle() by reflection, resolving each parameter
 * through the given container — the one piece QueueWorker and SyncQueue
 * both need identically, extracted once a second real call site needed
 * it. Every handle() parameter must be class-typed; a job's own
 * constructor is the only place scalar data comes from. Typed against
 * the generic Psr\Container\ContainerInterface rather than RequestScope
 * specifically — both AppScope and RequestScope already implement it, so
 * either can be passed without this class knowing which one it is.
 */
final class JobInvoker
{
    public static function invoke(Job $job, ContainerInterface $container): void
    {
        $method = new ReflectionMethod($job, 'handle');
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw UnresolvableJobParameterException::forParameter($job::class, $parameter->getName());
            }

            $arguments[] = $container->get($type->getName());
        }

        $method->invoke($job, ...$arguments);
    }
}
