<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
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
}
