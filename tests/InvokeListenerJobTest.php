<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use DateTimeImmutable;
use Kinetis\Container\AppScope;
use Kinetis\Queue\InvokeListenerJob;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Support\NormalizedPayload;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RichEvent;
use Kinetis\Queue\Tests\Fixtures\RichEventListener;
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
            new NormalizedPayload($serializedEvent['args']),
        );

        $job->handle($app->createRequestScope());

        self::assertSame(['hello from a worker'], $recorder->messages);
    }

    public function test_it_is_serializable_and_reconstructible_via_jobserializer_like_any_other_job(): void
    {
        $original = new InvokeListenerJob(TestListener::class, 'onTestEvent', TestEvent::class, new NormalizedPayload(['message' => 'x']));

        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserialize($serialized['class'], $serialized['args']);

        self::assertEquals($original, $restored);
    }

    /**
     * End to end, not just at the serialized-shape level: an event
     * carrying a BackedEnum case and a DateTimeImmutable survives being
     * wrapped in InvokeListenerJob, serialized as a Job in its own
     * right (exercising the NormalizedPayload composition — see that
     * class's own docblock), and reconstructed on the worker side with
     * both rich values intact.
     */
    public function test_handle_reconstructs_an_event_carrying_tagged_rich_types_correctly(): void
    {
        $recorder = new Recorder();

        $app = new AppScope();
        $app->instance(Recorder::class, $recorder);
        $app->boot();

        $occurredAt = new DateTimeImmutable('2024-03-14T15:09:26.535897+00:00');
        $serializedEvent = JobSerializer::serialize(new RichEvent([['id' => 1]], Priority::High, $occurredAt));

        $job = new InvokeListenerJob(
            RichEventListener::class,
            'onRichEvent',
            $serializedEvent['class'],
            new NormalizedPayload($serializedEvent['args']),
        );

        // The real point: serializing/deserializing InvokeListenerJob
        // itself, the same as every other job going through a real
        // backend, must not disturb its own already-normalized
        // $eventArgs.
        $reserialized = JobSerializer::serialize($job);
        $roundTripped = JobSerializer::deserializeJob($reserialized['class'], $reserialized['args']);

        $roundTripped->handle($app->createRequestScope());

        self::assertSame(
            '[{"id":1}]|high|' . $occurredAt->format('Y-m-d\TH:i:s.uP'),
            $recorder->messages[0],
        );
    }
}
