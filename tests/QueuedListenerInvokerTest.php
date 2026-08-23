<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\QueuedListenerInvoker;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\TestEvent;
use Kinetis\Queue\Tests\Fixtures\TestListener;
use PHPUnit\Framework\TestCase;

final class QueuedListenerInvokerTest extends TestCase
{
    public function test_invoke_pushes_an_invoke_listener_job_carrying_the_listener_and_event_data(): void
    {
        $queue = new InMemoryQueue();
        $invoker = new QueuedListenerInvoker($queue);

        // A real, already-resolved listener instance — the shape
        // EventDispatcher actually hands to a ListenerInvokerInterface.
        $listener = new TestListener(new Recorder());

        $invoker->invoke($listener, 'onTestEvent', new TestEvent('hello'));

        $queuedJob = $queue->pop();

        self::assertNotNull($queuedJob);
        self::assertSame(InvokeListenerJob::class, $queuedJob->class);
        self::assertSame(TestListener::class, $queuedJob->args['listenerClass']);
        self::assertSame('onTestEvent', $queuedJob->args['method']);
        self::assertSame(TestEvent::class, $queuedJob->args['eventClass']);
        self::assertSame(['message' => 'hello'], $queuedJob->args['eventArgs']);
    }
}
