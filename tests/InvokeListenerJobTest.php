<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\TestEvent;
use Kinetis\Queue\Tests\Fixtures\TestListener;
use PHPUnit\Framework\TestCase;

final class InvokeListenerJobTest extends TestCase
{
    public function test_handle_resolves_the_listener_and_invokes_the_named_method_with_the_reconstructed_event(): void
    {
        $recorder = new Recorder();

        $app = new AppScope();
        $app->instance(Recorder::class, $recorder);
        $app->boot();

        $serializedEvent = JobSerializer::serialize(new TestEvent('hello from a worker'));

        $job = new InvokeListenerJob(
            TestListener::class,
            'onTestEvent',
            $serializedEvent['class'],
            $serializedEvent['args'],
        );

        $job->handle($app->createRequestScope());

        self::assertSame(['hello from a worker'], $recorder->messages);
    }

    public function test_it_is_serializable_and_reconstructible_via_jobserializer_like_any_other_job(): void
    {
        $original = new InvokeListenerJob(TestListener::class, 'onTestEvent', TestEvent::class, ['message' => 'x']);

        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserialize($serialized['class'], $serialized['args']);

        self::assertEquals($original, $restored);
    }
}
