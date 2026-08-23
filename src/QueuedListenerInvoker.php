<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Events\ListenerInvokerInterface;

/**
 * Kinetis\Events\EventDispatcher routes a ShouldQueue listener's
 * invocation through whatever ListenerInvokerInterface is registered —
 * this is the one satellite-to-core implementation, per the design
 * recorded when this package first shipped: core owns
 * ListenerInvokerInterface, this package implements it, and core never
 * references kinetis/queue in any form.
 *
 * $listener is EventDispatcher's own already-resolved listener instance,
 * constructed with its own dependencies. It can't be pushed onto the
 * queue directly (a live object with resolved dependencies has nothing
 * serializable about it); InvokeListenerJob instead carries the
 * listener's class/method as plain strings and the event's own
 * serialized constructor data, via the same JobSerializer a real Job
 * uses, reconstructing both on the worker side later.
 */
final readonly class QueuedListenerInvoker implements ListenerInvokerInterface
{
    public function __construct(
        private QueueInterface $queue,
    ) {}

    #[\Override]
    public function invoke(object $listener, string $method, object $event): void
    {
        $serialized = JobSerializer::serialize($event);

        $this->queue->push(new InvokeListenerJob(
            $listener::class,
            $method,
            $serialized['class'],
            $serialized['args'],
        ));
    }
}
