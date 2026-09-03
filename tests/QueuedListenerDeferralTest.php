<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueuedListenerInvoker;
use Kinetis\Queue\Tests\Fixtures\ConstructionCountingListener;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\TestEvent;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end proof the class-string invoker seam exists for: a
 * ShouldQueue listener's own constructor never runs in the producer
 * process that dispatches the event — only once, later, in whatever
 * process actually pops and executes the resulting job.
 */
final class QueuedListenerDeferralTest extends TestCase
{
    public function test_dispatching_enqueues_with_zero_construction_and_the_worker_constructs_exactly_once(): void
    {
        ConstructionCountingListener::$constructions = 0;

        $queue = new InMemoryQueue();

        $registry = new EventListenerRegistry();
        $registry->register(ConstructionCountingListener::class);

        $producer = new AppScope();
        $producer->instance(EventListenerRegistry::class, $registry);
        $producer->instance(ListenerInvokerInterface::class, new QueuedListenerInvoker($queue));
        $producer->boot();

        $producer->createRequestScope()->get(EventDispatcher::class)->dispatch(new TestEvent('hello'));

        // The producer process never constructed the listener at all —
        // only its class name reached the queue.
        self::assertSame(0, ConstructionCountingListener::$constructions);

        $queuedJob = $queue->pop();
        self::assertNotNull($queuedJob);
        self::assertSame(InvokeListenerJob::class, $queuedJob->class);
        self::assertSame(ConstructionCountingListener::class, $queuedJob->args['listenerClass']);

        $job = JobSerializer::deserializeJob($queuedJob->class, $queuedJob->args);
        self::assertInstanceOf(InvokeListenerJob::class, $job);

        // A separate AppScope stands in for the worker process — the
        // listener is resolved fresh here, never sharing the producer's
        // own container.
        $worker = new AppScope();
        $worker->boot();

        $job->handle($worker->createRequestScope());

        self::assertSame(1, ConstructionCountingListener::$constructions);
    }
}
