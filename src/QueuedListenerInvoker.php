<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Container\RequestScope;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\Support\NormalizedPayload;

/**
 * Kinetis\Events\EventDispatcher routes a ShouldQueue listener's
 * invocation through whatever ListenerInvokerInterface is registered —
 * this is the one satellite-to-core implementation, per the design
 * recorded when this package first shipped: core owns
 * ListenerInvokerInterface, this package implements it, and core never
 * references kinetis/queue in any form.
 *
 * $listenerClass is a class-string, not a resolved object: EventDispatcher
 * checks the registry's own ShouldQueue flag before ever constructing a
 * listener, so nothing about the listener — its constructor, any
 * dependency it needs, anything a worker-only binding would supply — runs
 * in this producer process at all. InvokeListenerJob carries the
 * listener's class/method as plain strings and the event's own serialized
 * constructor data, via the same JobSerializer a real Job uses,
 * reconstructing and invoking the listener on the worker side later — see
 * that class's own docblock, and its handle() specifically, for where the
 * one and only construction happens. $scope is accepted only to satisfy
 * the shared interface; nothing here needs it, since nothing here
 * resolves anything.
 */
final readonly class QueuedListenerInvoker implements ListenerInvokerInterface
{
    public function __construct(
        private QueueInterface $queue,
    ) {}

    #[\Override]
    public function invoke(string $listenerClass, string $method, object $event, RequestScope $scope): void
    {
        $serialized = JobSerializer::serialize($event);

        $this->queue->push(new InvokeListenerJob(
            $listenerClass,
            $method,
            $serialized['class'],
            new NormalizedPayload($serialized['args']),
        ));
    }
}
