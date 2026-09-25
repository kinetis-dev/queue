<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Events\SynchronousListenerInvoker;
use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Exception\QueueNotClearableException;
use Kinetis\Queue\Exception\QueueUnavailableException;
use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\PackageBootstrap;
use Kinetis\Queue\QueuedListenerInvoker;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\Tests\Fixtures\DisposableApplicationQueue;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\NeverCalledQueue;
use Kinetis\Queue\Tests\Fixtures\TestEvent;
use Kinetis\Queue\Tests\Fixtures\TestListener;
use PHPUnit\Framework\TestCase;

/**
 * The bootstrap's job is to leave three bindings behind that still
 * answer correctly after the application's own bootstrap.php has
 * replaced any of them — so every test here registers, overrides the
 * way an application would, boots, and only then resolves.
 *
 * None of the four backend packages is installed in this package's own
 * test environment (see QueueFactoryTest), which is what lets these
 * tests use QUEUE_CONNECTION as a pure marker: a run that reaches
 * QueueFactory raises QueueUnavailableException, so "the configured
 * backend was never built" is a real assertion here rather than a
 * hopeful comment. `redis` stands for a configured backend that would
 * declare ClearableQueueInterface and `sqs` for one that would not.
 */
final class PackageBootstrapTest extends TestCase
{
    public function test_no_queue_connection_leaves_the_queue_bindings_unregistered(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertFalse($app->has(QueueInterface::class));
        self::assertFalse($app->has(ClearableQueueInterface::class));
    }

    /**
     * With nothing configured there is no queue to defer onto, so core's
     * own inline default has to be what boot() ends up leaving behind —
     * a ShouldQueue listener still runs, just synchronously.
     */
    public function test_no_queue_connection_leaves_the_synchronous_listener_invoker_in_place(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertInstanceOf(SynchronousListenerInvoker::class, $app->get(ListenerInvokerInterface::class));
    }

    /**
     * A configured queue is the whole of "queue my ShouldQueue
     * listeners" — no second registration for an application to
     * remember, and core's synchronous default (registered in boot(),
     * only where nothing is bound yet) never applies.
     */
    public function test_a_configured_queue_binds_the_queued_listener_invoker(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));

        $applicationQueue = new InMemoryQueue();
        $app->instance(QueueInterface::class, $applicationQueue);
        $app->boot();

        $invoker = $app->get(ListenerInvokerInterface::class);
        self::assertInstanceOf(QueuedListenerInvoker::class, $invoker);

        // Pushed onto the queue the application actually runs, not one
        // built from QUEUE_CONNECTION behind its back.
        $invoker->invoke(TestListener::class, 'onTestEvent', new TestEvent('hello'), $app->createRequestScope());

        $queuedJob = $applicationQueue->pop();
        self::assertNotNull($queuedJob);
        self::assertSame(InvokeListenerJob::class, $queuedJob->class);
        self::assertSame(TestListener::class, $queuedJob->args['listenerClass']);
    }

    /**
     * The invoker resolves QueueInterface on first use, not during
     * registration: reaching QueueFactory for an uninstalled backend is
     * what proves the resolution happened after every bootstrap had its
     * chance to override, rather than being captured up front.
     */
    public function test_the_listener_invoker_resolves_the_queue_only_when_it_is_first_used(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));
        $app->boot();

        $this->expectException(QueueUnavailableException::class);
        $app->get(ListenerInvokerInterface::class);
    }

    /**
     * bootstrap.php runs after this one, so an application that wants
     * its listeners inline against a configured queue says so and is
     * obeyed.
     */
    public function test_an_application_binding_the_listener_invoker_itself_wins(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));

        $invoker = new SynchronousListenerInvoker();
        $app->instance(ListenerInvokerInterface::class, $invoker);
        $app->boot();

        self::assertSame($invoker, $app->get(ListenerInvokerInterface::class));
    }

    /**
     * QUEUE_CONNECTION_NAME names the connection the binding builds, and
     * that connection's own selector is the gate: reaching QueueFactory
     * for the uninstalled backend, naming QUEUE_JOBS_CONNECTION, proves
     * both the gate and the name passed through.
     */
    public function test_a_named_connection_gates_on_its_own_scoped_selector(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register(
            $app,
            new Config(['QUEUE_CONNECTION_NAME' => 'jobs', 'QUEUE_JOBS_CONNECTION' => 'redis']),
        );
        $app->boot();

        $this->expectException(QueueUnavailableException::class);
        $this->expectExceptionMessage('Cannot use QUEUE_JOBS_CONNECTION="redis"');
        $app->get(QueueInterface::class);
    }

    public function test_a_named_connection_is_inert_with_only_the_global_selector(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register(
            $app,
            new Config(['QUEUE_CONNECTION_NAME' => 'jobs', 'QUEUE_CONNECTION' => 'redis']),
        );
        $app->boot();

        self::assertFalse($app->has(QueueInterface::class));
        self::assertFalse($app->has(ClearableQueueInterface::class));
        self::assertInstanceOf(SynchronousListenerInvoker::class, $app->get(ListenerInvokerInterface::class));
    }

    /**
     * Rejected at registration even with no selector configured: an
     * unvalidated name would derive a key nobody set and leave queued
     * listeners silently running inline.
     */
    public function test_a_malformed_connection_name_throws_before_the_gate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid connection name "Jobs" from QUEUE_CONNECTION_NAME: a connection name is lowercase ASCII '
            . 'letters and digits, starting with a letter (^[a-z][a-z0-9]*$).',
        );
        new PackageBootstrap()->register(new AppScope(), new Config(['QUEUE_CONNECTION_NAME' => 'Jobs']));
    }

    public function test_a_blank_connection_name_binds_the_default_connection(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register(
            $app,
            new Config(['QUEUE_CONNECTION_NAME' => '', 'QUEUE_CONNECTION' => 'redis']),
        );
        $app->boot();

        $this->expectException(QueueUnavailableException::class);
        $this->expectExceptionMessage('Cannot use QUEUE_CONNECTION="redis"');
        $app->get(QueueInterface::class);
    }

    public function test_registering_builds_no_backend_until_the_queue_is_resolved(): void
    {
        $app = new AppScope();

        // Returning at all is half the assertion: QueueFactory would
        // throw here for an uninstalled backend if it ran during
        // registration.
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));
        $app->boot();

        $this->expectException(QueueUnavailableException::class);
        $app->get(QueueInterface::class);
    }

    /**
     * An application queue never reaches the factory closure that
     * registers disposal, so the connection behind it stays open for
     * whoever opened it — capability or no capability.
     */
    public function test_an_application_queue_is_never_disposed_by_this_bootstrap(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));

        $applicationQueue = new DisposableApplicationQueue();
        $app->instance(QueueInterface::class, $applicationQueue);
        $app->boot();

        self::assertSame($applicationQueue, $app->get(QueueInterface::class));

        $app->dispose();

        self::assertSame(0, $applicationQueue->disposeCalls, "the application's own queue is the application's to close");
    }

    public function test_the_capability_answers_with_the_application_queue_that_replaced_a_clearable_connection(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'redis']));

        $applicationQueue = new NeverCalledQueue();
        $app->instance(QueueInterface::class, $applicationQueue);
        $app->boot();

        self::assertSame($applicationQueue, $app->get(QueueInterface::class));

        // The configured connection would have been clearable. What the
        // application actually runs is not, and that is the answer the
        // capability has to give — naming the backend a developer can
        // act on, rather than the one QUEUE_CONNECTION mentions.
        $this->expectException(QueueNotClearableException::class);
        $this->expectExceptionMessage(NeverCalledQueue::class);
        $app->get(ClearableQueueInterface::class);
    }

    public function test_the_capability_answers_with_the_application_queue_that_replaced_a_non_clearable_connection(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'sqs']));

        $applicationQueue = new InMemoryQueue();
        $app->instance(QueueInterface::class, $applicationQueue);
        $app->boot();

        self::assertSame($applicationQueue, $app->get(ClearableQueueInterface::class));
    }

    /**
     * An application running two queues — one it pushes to, a clearable
     * one it administers — registers both, and its own capability
     * binding is the one that answers rather than being derived from
     * QueueInterface.
     */
    public function test_an_application_binding_the_capability_itself_wins(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['QUEUE_CONNECTION' => 'sqs']));

        $clearable = new InMemoryQueue();
        $app->instance(QueueInterface::class, new NeverCalledQueue());
        $app->instance(ClearableQueueInterface::class, $clearable);
        $app->boot();

        self::assertSame($clearable, $app->get(ClearableQueueInterface::class));
    }
}
