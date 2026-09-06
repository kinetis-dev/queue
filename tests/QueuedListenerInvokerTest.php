<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use DateTimeImmutable;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\QueuedListenerInvoker;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\RichEvent;
use Kinetis\Queue\Tests\Fixtures\RichEventListener;
use Kinetis\Queue\Tests\Fixtures\TestEvent;
use Kinetis\Queue\Tests\Fixtures\TestListener;
use Kinetis\Queue\Tests\Fixtures\UnserializableEvent;
use PHPUnit\Framework\TestCase;

final class QueuedListenerInvokerTest extends TestCase
{
    /**
     * A real RequestScope — the interface requires one, but this
     * invoker never actually resolves anything from it (see its own
     * docblock): it enqueues from the given class-string alone.
     */
    private function scope(): \Kinetis\Container\RequestScope
    {
        $app = new AppScope();
        $app->boot();

        return $app->createRequestScope();
    }

    public function test_invoke_pushes_an_invoke_listener_job_carrying_the_listener_and_event_data(): void
    {
        $queue = new InMemoryQueue();
        $invoker = new QueuedListenerInvoker($queue);

        // A class-string, never a constructed instance — the whole
        // point: EventDispatcher never builds a queued listener before
        // handing it to this invoker.
        $invoker->invoke(TestListener::class, 'onTestEvent', new TestEvent('hello'), $this->scope());

        $queuedJob = $queue->pop();

        self::assertNotNull($queuedJob);
        self::assertSame(InvokeListenerJob::class, $queuedJob->class);
        self::assertSame(TestListener::class, $queuedJob->args['listenerClass']);
        self::assertSame('onTestEvent', $queuedJob->args['method']);
        self::assertSame(TestEvent::class, $queuedJob->args['eventClass']);
        self::assertSame(['message' => 'hello'], $queuedJob->args['eventArgs']);
    }

    /**
     * The event's own constructor arguments go through the identical
     * JobSerializer::serialize() wire-value validation a Job's own
     * arguments do — a nested unsupported value is rejected here too,
     * before InvokeListenerJob is even constructed, not discovered later
     * on the worker side.
     */
    public function test_invoke_rejects_an_event_carrying_an_unsupported_nested_value(): void
    {
        $queue = new InMemoryQueue();
        $invoker = new QueuedListenerInvoker($queue);

        $this->expectException(UnserializableJobException::class);
        $invoker->invoke(TestListener::class, 'onTestEvent', new UnserializableEvent(['inner' => new \stdClass()]), $this->scope());
    }

    /**
     * A BackedEnum case and a DateTimeImmutable — the same rich types a
     * Job's own constructor arguments support — reach the queue in the
     * scalar form the event's own parameter types restore them from.
     */
    public function test_invoke_writes_a_rich_event_argument_in_its_scalar_wire_form(): void
    {
        $queue = new InMemoryQueue();
        $invoker = new QueuedListenerInvoker($queue);

        $occurredAt = new DateTimeImmutable('2024-03-14T15:09:26.535897+00:00');
        $invoker->invoke(RichEventListener::class, 'onRichEvent', new RichEvent([], Priority::High, $occurredAt), $this->scope());

        $queuedJob = $queue->pop();

        self::assertNotNull($queuedJob);
        self::assertSame('high', $queuedJob->args['eventArgs']['priority']);
        self::assertSame(
            $occurredAt->format('Y-m-d\TH:i:s.uP'),
            $queuedJob->args['eventArgs']['occurredAt'],
        );
    }
}
