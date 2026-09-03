<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\RequestScope;
use Kinetis\Queue\Support\NormalizedPayload;

/**
 * The job QueuedListenerInvoker pushes for a ShouldQueue listener — plain
 * data only (class-strings, the event's own serialized constructor
 * arguments), reconstructing both the listener and the event on the
 * worker side. handle() is typed against the concrete RequestScope class
 * specifically, not the generic Psr\Container\ContainerInterface: only
 * `RequestScope::class` itself is the id AppScope::createRequestScope()
 * registers a scope onto itself as, the same self-injection mechanism
 * BearerAuthMiddleware/JwtAuthMiddleware already rely on.
 *
 * $eventArgs is a NormalizedPayload, not a plain array — this job is
 * itself serialized as the actual Job pushed onto the queue, and its own
 * $eventArgs already holds an earlier JobSerializer::serialize() call's
 * output (the event's, from QueuedListenerInvoker). Wrapping it is what
 * lets JobSerializer::serialize() embed that already-normalized data
 * without re-walking it — see Kinetis\Queue\Support\NormalizedPayload's
 * own docblock for why this has to be a real type, not an array shape.
 */
final readonly class InvokeListenerJob implements Job
{
    /**
     * @param class-string $listenerClass
     * @param class-string $eventClass
     */
    public function __construct(
        private string $listenerClass,
        private string $method,
        private string $eventClass,
        private NormalizedPayload $eventArgs,
    ) {}

    public function handle(RequestScope $scope): void
    {
        $listener = $scope->get($this->listenerClass);
        $event = JobSerializer::deserialize($this->eventClass, $this->eventArgs->wireArgs);

        $listener->{$this->method}($event);
    }
}
