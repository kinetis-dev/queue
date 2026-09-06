<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\RequestScope;

/**
 * The job QueuedListenerInvoker pushes for a ShouldQueue listener —
 * plain data only (class-strings, the event's serialized constructor
 * arguments), reconstructing both the listener and the event on the
 * worker side.
 *
 * handle() is typed against the concrete RequestScope class, not
 * Psr\Container\ContainerInterface: `RequestScope::class` is the id
 * AppScope::createRequestScope() registers a scope onto itself as, the
 * same self-injection BearerAuthMiddleware and JwtAuthMiddleware rely on.
 *
 * $eventArgs already holds an earlier JobSerializer::serialize() call's
 * output, so serializing this job walks values that are already wire
 * values and passes them through unchanged. The event's own constructor
 * parameter types are what restore it on the worker side.
 */
final readonly class InvokeListenerJob implements Job
{
    /**
     * @param class-string $listenerClass
     * @param class-string $eventClass
     * @param array<string, mixed> $eventArgs
     */
    public function __construct(
        private string $listenerClass,
        private string $method,
        private string $eventClass,
        private array $eventArgs,
    ) {}

    public function handle(RequestScope $scope): void
    {
        $listener = $scope->get($this->listenerClass);
        $event = JobSerializer::deserialize($this->eventClass, $this->eventArgs);

        $listener->{$this->method}($event);
    }
}
