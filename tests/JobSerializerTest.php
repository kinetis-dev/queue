<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use Kinetis\Queue\Tests\Fixtures\UnserializableJob;
use PHPUnit\Framework\TestCase;

final class JobSerializerTest extends TestCase
{
    public function test_serialize_captures_the_class_and_constructor_arguments(): void
    {
        $serialized = JobSerializer::serialize(new RecordingJob('hello'));

        self::assertSame(RecordingJob::class, $serialized['class']);
        self::assertSame(['message' => 'hello'], $serialized['args']);
    }

    public function test_deserialize_reconstructs_an_equivalent_job(): void
    {
        $job = JobSerializer::deserialize(RecordingJob::class, ['message' => 'hello']);

        self::assertInstanceOf(RecordingJob::class, $job);
        self::assertSame('hello', $job->message);
    }

    public function test_a_round_trip_produces_an_equivalent_job(): void
    {
        $original = new RecordingJob('round trip');
        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserialize($serialized['class'], $serialized['args']);

        self::assertEquals($original, $restored);
    }

    public function test_a_constructor_parameter_with_no_matching_property_throws(): void
    {
        $this->expectException(UnserializableJobException::class);

        JobSerializer::serialize(new UnserializableJob('Alon'));
    }

    public function test_redact_replaces_only_the_arguments_marked_sensitive(): void
    {
        $serialized = JobSerializer::serialize(new SensitiveFailingJob(4812, 'ana@example.com', 'not-a-real-token'));

        self::assertSame(
            ['userId' => 4812, 'email' => '[redacted]', 'resetToken' => '[redacted]'],
            JobSerializer::redact($serialized['class'], $serialized['args']),
        );
    }

    public function test_redact_leaves_a_job_with_nothing_marked_untouched(): void
    {
        $serialized = JobSerializer::serialize(new RecordingJob('hello'));

        self::assertSame($serialized['args'], JobSerializer::redact($serialized['class'], $serialized['args']));
    }

    /**
     * A class that no longer loads gives no way to tell which arguments
     * are sensitive, so all of them go rather than none — the keys stay,
     * so the entry still shows the shape of the payload.
     */
    public function test_redact_redacts_every_argument_when_the_class_cannot_be_loaded(): void
    {
        self::assertSame(
            ['email' => '[redacted]', 'resetToken' => '[redacted]'],
            JobSerializer::redact('App\\Jobs\\Deleted', ['email' => 'ana@example.com', 'resetToken' => 'not-a-real-token']),
        );
    }

    public function test_redact_ignores_a_marked_parameter_absent_from_the_arguments(): void
    {
        self::assertSame(
            ['userId' => 4812],
            JobSerializer::redact(SensitiveFailingJob::class, ['userId' => 4812]),
        );
    }
}
