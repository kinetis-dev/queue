<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\RequestScope;

/**
 * The job QueuedListenerInvoker pushes for a ShouldQueue listener — plain
 * data only (class-strings, the event's own serialized constructor
 * arguments), reconstructing both the listener and the event on the
 * worker side. handle() is typed against the concrete RequestScope class
 * specifically, not the generic Psr\Container\ContainerInterface: only
 * `RequestScope::class` itself is the id AppScope::createRequestScope()
 * registers a scope onto itself as, the same self-injection mechanism
 * BearerAuthMiddleware/JwtAuthMiddleware already rely on.
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
