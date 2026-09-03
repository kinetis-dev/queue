<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use DateTimeImmutable;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Tests\Fixtures\NotAJobObject;
use Kinetis\Queue\Tests\Fixtures\PayloadJob;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveMapPayloadJob;
use Kinetis\Queue\Tests\Fixtures\ThrowsInConstructorJob;
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

    /**
     * A payload spanning the full portable wire-value matrix — a nested
     * list of maps, a BackedEnum case, a DateTimeImmutable — round-trips
     * through serialize() then deserializeJob() as one real object, not
     * just piece by piece via WireValueTest's own lower-level proof.
     */
    public function test_a_rich_payload_round_trips_through_serialize_and_deserialize_job(): void
    {
        $occurredAt = new DateTimeImmutable('2024-03-14T15:09:26.535897+00:00');
        $original = new PayloadJob([
            'priority' => Priority::High,
            'occurredAt' => $occurredAt,
            'items' => [['id' => 1, 'tags' => ['a', 'b']], ['id' => 2, 'tags' => []]],
            'ratio' => 4.0,
        ]);

        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserializeJob($serialized['class'], $serialized['args']);

        self::assertInstanceOf(PayloadJob::class, $restored);
        self::assertEquals($original->payload, $restored->payload);
    }

    public function test_serialize_rejects_an_unsupported_argument_value_with_a_path_naming_argument(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('"payload"');

        JobSerializer::serialize(new PayloadJob(static fn () => null));
    }

    public function test_serialize_rejects_an_unsupported_value_nested_inside_a_supported_array(): void
    {
        $this->expectException(UnserializableJobException::class);
        // The map key itself ("inner") is a fingerprint, not the raw
        // key, per WireValue's own key-rendering contract — see
        // WireValueTest for that mechanism's own dedicated coverage.
        // This test's own point is the *argument* name still appearing.
        $this->expectExceptionMessage('"payload.<map-key');

        JobSerializer::serialize(new PayloadJob(['inner' => new \stdClass()]));
    }

    /**
     * A #[Sensitive] argument's own nested rejection never surfaces the
     * nested path — only the top-level argument name, unlike an
     * ordinary argument's own rejection two tests above, which does
     * name "payload.inner" directly. A map key nested inside a
     * #[Sensitive] value can itself be secret (see
     * SensitiveMapPayloadJob's own docblock), which is exactly what
     * this closes.
     */
    public function test_serialize_never_names_a_nested_path_within_a_sensitive_argument(): void
    {
        try {
            JobSerializer::serialize(new SensitiveMapPayloadJob(['actual-secret-token' => new \stdClass()]));
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException $e) {
            self::assertStringNotContainsString('actual-secret-token', $e->getMessage());
            self::assertStringContainsString('"$tokens"', $e->getMessage());
            self::assertStringNotContainsString('tokens.actual', $e->getMessage());
        }
    }

    public function test_deserialize_rejects_a_class_that_does_not_exist(): void
    {
        $this->expectException(JobReconstructionException::class);

        JobSerializer::deserialize('App\\Jobs\\DoesNotExist', []);
    }

    public function test_deserialize_job_rejects_a_class_that_does_not_implement_job(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('does not implement');

        JobSerializer::deserializeJob(NotAJobObject::class, ['value' => 'x']);
    }

    /**
     * deserialize() — the general, event-reconstruction path — is
     * deliberately not required to implement Job at all.
     */
    public function test_deserialize_accepts_a_class_that_does_not_implement_job(): void
    {
        $object = JobSerializer::deserialize(NotAJobObject::class, ['value' => 'x']);

        self::assertInstanceOf(NotAJobObject::class, $object);
        self::assertSame('x', $object->value);
    }

    public function test_deserialize_rejects_a_payload_missing_a_required_argument(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('$message');

        JobSerializer::deserialize(RecordingJob::class, []);
    }

    public function test_deserialize_rejects_a_payload_carrying_an_unrecognized_argument(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('$extra');

        JobSerializer::deserialize(RecordingJob::class, ['message' => 'hi', 'extra' => 'unexpected']);
    }

    public function test_deserialize_job_wraps_a_constructor_failure_chaining_the_original(): void
    {
        try {
            JobSerializer::deserializeJob(ThrowsInConstructorJob::class, ['value' => 'x']);
            self::fail('Expected JobReconstructionException.');
        } catch (JobReconstructionException $e) {
            self::assertStringContainsString('the constructor itself always fails', $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertSame('the constructor itself always fails', $e->getPrevious()->getMessage());
        }
    }
}
