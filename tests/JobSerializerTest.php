<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use DateTimeImmutable;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\Tests\Fixtures\CustomDate;
use Kinetis\Queue\Tests\Fixtures\DateInterfaceJob;
use Kinetis\Queue\Tests\Fixtures\DatedJob;
use Kinetis\Queue\Tests\Fixtures\MismatchedEnumJob;
use Kinetis\Queue\Tests\Fixtures\NotAJobObject;
use Kinetis\Queue\Tests\Fixtures\NullablePriorityJob;
use Kinetis\Queue\Tests\Fixtures\PayloadJob;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\RichEvent;
use Kinetis\Queue\Tests\Fixtures\SensitiveFailingJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveMapPayloadJob;
use Kinetis\Queue\Tests\Fixtures\SensitiveConstructorFailureJob;
use Kinetis\Queue\Tests\Fixtures\ThrowsInConstructorJob;
use Kinetis\Queue\Tests\Fixtures\UnionArgumentJob;
use Kinetis\Queue\Tests\Fixtures\UnserializableJob;
use Kinetis\Queue\Tests\Fixtures\UntypedArgumentJob;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * A BackedEnum case and a DateTimeImmutable are restored from the
     * constructor parameter's declared type, alongside an ordinary
     * nested list of maps, as one real object rather than field by
     * field.
     */
    public function test_top_level_rich_arguments_round_trip_through_their_declared_types(): void
    {
        $occurredAt = new DateTimeImmutable('2024-03-14T15:09:26.535897+00:00');
        $original = new RichEvent([['id' => 1, 'tags' => ['a', 'b']], ['id' => 2, 'tags' => []]], Priority::High, $occurredAt);

        $serialized = JobSerializer::serialize($original);

        self::assertSame('high', $serialized['args']['priority']);
        self::assertSame('2024-03-14T15:09:26.535897+00:00', $serialized['args']['occurredAt']);

        $restored = JobSerializer::deserialize($serialized['class'], $serialized['args']);

        self::assertEquals($original, $restored);
    }

    /**
     * A JSON array carries nothing saying what a bare string was meant
     * to become, so an enum case or a date nested inside one is rejected
     * at push() rather than silently restored as a scalar on the worker
     * side.
     */
    public function test_serialize_rejects_an_enum_or_date_nested_inside_an_array(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('nested inside an array');

        JobSerializer::serialize(new PayloadJob(['priority' => Priority::High]));
    }

    /**
     * A subclass carries state the scalar timestamp cannot hold, so it
     * is rejected rather than silently written as a plain date — and the
     * message says that, not the unrelated "nested inside an array"
     * reason a value reaching the same rejection from inside an array
     * gets.
     */
    public function test_serialize_rejects_a_datetime_subclass_naming_that_reason(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('DateTimeImmutable subclass');

        JobSerializer::serialize(new DatedJob(new CustomDate('2024-03-14T15:09:26+00:00')));
    }

    public function test_an_ordinary_json_payload_round_trips_untouched(): void
    {
        $original = new PayloadJob([
            'items' => [['id' => 1, 'tags' => ['a', 'b']], ['id' => 2, 'tags' => []]],
            'ratio' => 4.0,
            'enabled' => true,
            'missing' => null,
        ]);

        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserializeJob($serialized['class'], $serialized['args']);

        self::assertInstanceOf(PayloadJob::class, $restored);
        self::assertSame($original->payload, $restored->payload);
    }

    public function test_serialize_rejects_an_unsupported_argument_value_with_a_path_naming_argument(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('"payload"');

        JobSerializer::serialize(new PayloadJob(static fn () => null));
    }

    /**
     * A list index describes the payload's shape and is kept; a map key
     * is application data, so the entry is located by ordinal position
     * instead.
     */
    public function test_serialize_names_the_path_to_a_value_rejected_inside_an_array(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('"payload[1].{0}"');

        JobSerializer::serialize(new PayloadJob([['ok' => 1], ['inner' => new \stdClass()]]));
    }

    /**
     * The same rule stated as the guarantee it exists for: an ordinary,
     * unmarked map argument can still hold a key that is itself a
     * secret, so no key ever reaches the message.
     */
    public function test_serialize_never_names_a_map_key_in_the_rejection_message(): void
    {
        try {
            JobSerializer::serialize(new PayloadJob(['sk-live-do-not-log' => new \stdClass()]));
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException $e) {
            self::assertStringNotContainsString('sk-live-do-not-log', $e->getMessage());
            self::assertStringContainsString('"payload.{0}"', $e->getMessage());
        }
    }

    /**
     * A nullable enum parameter is still one named type naming the
     * enum's own class, so it says exactly what the stored backing value
     * comes back as.
     */
    public function test_a_nullable_enum_parameter_round_trips(): void
    {
        $original = new NullablePriorityJob(Priority::Low);
        $serialized = JobSerializer::serialize($original);

        self::assertSame('low', $serialized['args']['mode']);
        self::assertEquals($original, JobSerializer::deserialize($serialized['class'], $serialized['args']));
    }

    public function test_a_nullable_enum_parameter_round_trips_a_null(): void
    {
        $serialized = JobSerializer::serialize(new NullablePriorityJob(null));

        self::assertNull($serialized['args']['mode']);
        self::assertNull(JobSerializer::deserializeJob($serialized['class'], $serialized['args'])->mode);
    }

    /**
     * An untyped parameter, `mixed`, a union, an interface and a
     * supertype all fail to state which class a stored scalar comes
     * back as. serialize() rejects the value there, at push(), rather
     * than writing a scalar no worker can turn back into what it was.
     *
     * @param callable(): object $build
     */
    #[DataProvider('ambiguouslyDeclaredRichArguments')]
    public function test_serialize_rejects_a_rich_value_whose_parameter_cannot_restore_it(callable $build, string $expectedFragment): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage($expectedFragment);

        JobSerializer::serialize($build());
    }

    /**
     * @return array<string, array{callable(): object, string}>
     */
    public static function ambiguouslyDeclaredRichArguments(): array
    {
        return [
            'an enum against mixed' => [
                static fn (): object => new PayloadJob(Priority::High),
                'does not declare that exact enum class',
            ],
            'a date against mixed' => [
                static fn (): object => new PayloadJob(new DateTimeImmutable('2024-03-14T15:09:26+00:00')),
                'does not declare exactly DateTimeImmutable',
            ],
            'an enum against no type at all' => [
                static fn (): object => new UntypedArgumentJob(Priority::High),
                'does not declare that exact enum class',
            ],
            'an enum against a union' => [
                static fn (): object => new UnionArgumentJob(Priority::High),
                'does not declare that exact enum class',
            ],
            'a date against DateTimeInterface' => [
                static fn (): object => new DateInterfaceJob(new DateTimeImmutable('2024-03-14T15:09:26+00:00')),
                'does not declare exactly DateTimeImmutable',
            ],
            'a case of an enum the parameter does not declare' => [
                static fn (): object => new MismatchedEnumJob(Priority::High),
                'does not declare that exact enum class',
            ],
        ];
    }

    /**
     * A self-referential array has no depth to reach the end of.
     * Traversing one would exhaust the process, so the depth bound turns
     * it into an ordinary push()-time rejection that completes.
     */
    public function test_serialize_rejects_a_self_referential_array(): void
    {
        $payload = ['label' => 'top'];
        $payload['self'] = &$payload;

        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('nests arrays more than 32 levels deep');

        JobSerializer::serialize(new PayloadJob($payload));
    }

    public function test_serialize_rejects_an_array_nested_past_the_depth_bound(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('nests arrays more than 32 levels deep');

        JobSerializer::serialize(new PayloadJob(self::nestedArray(33)));
    }

    /**
     * The other side of the same bound: a payload sitting exactly on it
     * still round trips, so the limit is the documented 32 rather than
     * whatever the traversal happens to reach.
     */
    public function test_an_array_nested_exactly_to_the_depth_bound_round_trips(): void
    {
        $original = new PayloadJob(self::nestedArray(32));

        $serialized = JobSerializer::serialize($original);
        $restored = JobSerializer::deserializeJob($serialized['class'], $serialized['args']);

        self::assertInstanceOf(PayloadJob::class, $restored);
        self::assertSame($original->payload, $restored->payload);
    }

    /**
     * A map nested exactly $levels arrays deep, with a scalar leaf.
     *
     * @return array<string, mixed>
     */
    private static function nestedArray(int $levels): array
    {
        $value = ['leaf' => 'bottom'];

        for ($i = 1; $i < $levels; ++$i) {
            $value = ['inner' => $value];
        }

        return $value;
    }

    /**
     * A rejection inside a #[Sensitive] argument names only that
     * argument, never the path within it — unlike the test above, which
     * names "payload.inner" directly. A map key nested inside a
     * #[Sensitive] value can itself be a secret.
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

    /**
     * A constructor that rejects what it was handed can quote it, so a
     * class supplied a #[Sensitive] argument gets neither the cause's
     * message nor the cause itself — the class name is the whole report.
     */
    public function test_a_constructor_failure_carries_no_cause_when_an_argument_is_sensitive(): void
    {
        try {
            JobSerializer::deserializeJob(SensitiveConstructorFailureJob::class, ['apiKey' => 'sk-live-do-not-log']);
            self::fail('Expected JobReconstructionException.');
        } catch (JobReconstructionException $e) {
            self::assertStringNotContainsString('sk-live-do-not-log', $e->getMessage());
            self::assertStringContainsString('#[Sensitive]', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
