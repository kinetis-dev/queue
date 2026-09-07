<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Error;
use Kinetis\Queue\Exception\InvalidQueueArgumentException;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\MalformedQueuedJobDataException;
use Kinetis\Queue\QueueContract;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class QueueContractTest extends TestCase
{
    public function test_a_valid_queue_name_is_accepted(): void
    {
        QueueContract::assertValidQueueName('high-priority_2');

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueueNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => ['not a name'];
        yield 'a dot' => ['queue.delay'];
        yield 'a slash' => ['tenant/queue'];
        yield 'a null byte' => ["default\0"];
        yield 'a newline' => ["default\n"];
        yield 'over the length limit' => [str_repeat('a', 81)];
    }

    #[DataProvider('invalidQueueNames')]
    public function test_an_invalid_queue_name_is_rejected(string $queue): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidQueueName($queue);
    }

    /**
     * A dot is what every DelayLadder name and every internal Redis key
     * carries, so rejecting it here is what keeps a caller-supplied name
     * from ever colliding with one.
     */
    public function test_a_name_at_the_length_limit_is_accepted(): void
    {
        QueueContract::assertValidQueueName(str_repeat('a', 80));

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_prefix_means_no_prefix_and_is_valid(): void
    {
        QueueContract::assertValidQueueNamePrefix('');

        $this->addToAssertionCount(1);
    }

    public function test_a_prefix_is_held_to_the_same_grammar_a_name_is(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidQueueNamePrefix('tenant one');
    }

    public function test_pop_accepts_a_zero_or_positive_timeout(): void
    {
        QueueContract::assertValidPopArguments(0, ['default']);
        QueueContract::assertValidPopArguments(30, ['high', 'default']);

        $this->addToAssertionCount(2);
    }

    public function test_pop_rejects_a_negative_timeout(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidPopArguments(-1, ['default']);
    }

    public function test_pop_accepts_an_empty_queue_list(): void
    {
        QueueContract::assertValidPopArguments(0, []);

        $this->addToAssertionCount(1);
    }

    public function test_pop_rejects_an_invalid_name_anywhere_in_the_list(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidPopArguments(0, ['default', 'not a name']);
    }

    /**
     * The whole list is checked before anything acts on any of it, so
     * `--queue=default,not a name` cannot clear `default` and then reject
     * the rest.
     */
    public function test_a_duplicate_queue_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);
        $this->expectExceptionMessage('more than once');

        QueueContract::assertValidQueueList(['default', 'high', 'default']);
    }

    public function test_push_arguments_are_validated_together(): void
    {
        QueueContract::assertValidPushArguments(0, 'default', null);
        QueueContract::assertValidPushArguments(60, 'default', 3);

        $this->addToAssertionCount(2);
    }

    public function test_push_rejects_a_negative_delay(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidPushArguments(-1, 'default', null);
    }

    public function test_push_rejects_an_invalid_queue_name(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidPushArguments(0, 'not a name', null);
    }

    public function test_push_rejects_a_negative_max_attempts(): void
    {
        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidPushArguments(0, 'default', -1);
    }

    /**
     * Zero means "never retry", a real setting, not a mistake.
     */
    public function test_a_max_attempts_of_zero_is_accepted(): void
    {
        QueueContract::assertValidMaxAttempts(0);
        QueueContract::assertValidMaxAttempts(null);

        $this->addToAssertionCount(2);
    }

    public function test_attempts_is_one_indexed(): void
    {
        QueueContract::assertValidAttempts(1);

        $this->expectException(InvalidQueueArgumentException::class);

        QueueContract::assertValidAttempts(0);
    }

    public function test_stored_int_reads_an_int_and_a_decimal_string(): void
    {
        self::assertSame(5, QueueContract::storedInt(5, 'attempts', 0));
        self::assertSame(5, QueueContract::storedInt('5', 'attempts', 0));
        self::assertSame(0, QueueContract::storedInt('0', 'attempts', 0));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrepresentableIntegers(): iterable
    {
        yield 'a word' => ['not a number'];
        yield 'an empty string' => [''];
        yield 'a float string' => ['1.5'];
        yield 'a hex string' => ['0x10'];
        yield 'an embedded newline' => ["1\n2"];
        yield 'a null byte' => ["1\0"];
        yield 'past PHP_INT_MAX' => ['9223372036854775808'];
        yield 'a float' => [1.5];
        yield 'a bool' => [true];
        yield 'null' => [null];
        yield 'an array' => [[1]];
        yield 'an object' => [new stdClass()];
    }

    /**
     * A `(int)` cast would read every one of these as 0, which
     * QueueWorker's exhaustion check would then take for a legitimate
     * count.
     */
    #[DataProvider('unrepresentableIntegers')]
    public function test_stored_int_rejects_a_value_that_is_not_a_representable_integer(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"attempts"');

        QueueContract::storedInt($raw, 'attempts', 0);
    }

    public function test_stored_int_rejects_a_value_below_the_minimum(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('out of bounds');

        QueueContract::storedInt(-1, 'attempts', 0);
    }

    /**
     * The three backends storing a completed-attempts count add one to
     * the result, and PHP_INT_MAX + 1 silently becomes a float.
     */
    public function test_stored_int_rejects_a_value_above_the_maximum(): void
    {
        self::assertSame(PHP_INT_MAX - 1, QueueContract::storedInt(PHP_INT_MAX - 1, 'attempts', 0, PHP_INT_MAX - 1));

        $this->expectException(MalformedQueuedJobDataException::class);

        QueueContract::storedInt(PHP_INT_MAX, 'attempts', 0, PHP_INT_MAX - 1);
    }

    public function test_stored_nullable_int_reads_null_as_no_stored_override(): void
    {
        self::assertNull(QueueContract::storedNullableInt(null, 'maxAttempts', 0));
        self::assertSame(0, QueueContract::storedNullableInt(0, 'maxAttempts', 0));
        self::assertSame(3, QueueContract::storedNullableInt('3', 'maxAttempts', 0));
    }

    public function test_stored_nullable_int_still_enforces_the_minimum(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);

        QueueContract::storedNullableInt(-1, 'maxAttempts', 0);
    }

    public function test_stored_json_array_decodes_an_object_and_a_list(): void
    {
        self::assertSame(
            ['class' => 'App\\Job', 'args' => []],
            QueueContract::storedJsonArray('{"class":"App\\\\Job","args":[]}', 'payload'),
        );
        self::assertSame([1, 2, 3], QueueContract::storedJsonArray('[1,2,3]', 'payload'));
    }

    public function test_stored_json_array_rejects_invalid_json(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('not valid JSON');

        QueueContract::storedJsonArray('{not valid json', 'payload');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonArrayJson(): iterable
    {
        yield 'a string' => ['"just a string"'];
        yield 'a number' => ['42'];
        yield 'a bool' => ['true'];
        yield 'null' => ['null'];
    }

    #[DataProvider('nonArrayJson')]
    public function test_stored_json_array_rejects_valid_json_that_is_not_an_array(string $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('not the expected shape');

        QueueContract::storedJsonArray($raw, 'payload');
    }

    public function test_stored_class_accepts_a_non_empty_string(): void
    {
        self::assertSame('App\\Job', QueueContract::storedClass('App\\Job'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStoredClasses(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
        yield 'an int' => [42];
        yield 'an array' => [['App\\Job']];
    }

    #[DataProvider('invalidStoredClasses')]
    public function test_stored_class_rejects_anything_else(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"class"');

        QueueContract::storedClass($raw);
    }

    public function test_stored_queue_name_accepts_a_name_the_grammar_allows(): void
    {
        self::assertSame('high-priority_2', QueueContract::storedQueueName('high-priority_2'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStoredQueueNames(): iterable
    {
        yield 'null' => [null];
        yield 'an int' => [42];
        yield 'an empty string' => [''];
        yield 'a trailing space' => ['default '];
        yield 'over the length limit' => [str_repeat('a', 81)];
    }

    /**
     * A stored name a caller could never have pushed is corruption in the
     * backend's own storage, so it settles rather than escaping pop() as
     * the caller-facing InvalidQueueArgumentException.
     */
    #[DataProvider('invalidStoredQueueNames')]
    public function test_stored_queue_name_rejects_anything_the_grammar_does(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"queue"');

        QueueContract::storedQueueName($raw);
    }

    public function test_stored_args_accepts_a_string_keyed_map_and_an_empty_array(): void
    {
        self::assertSame(['foo' => 'bar'], QueueContract::storedArgs(['foo' => 'bar']));
        self::assertSame([], QueueContract::storedArgs([]));
    }

    /**
     * A JSON list decodes to an integer-keyed array, which no real push()
     * ever writes — JobSerializer keys args by constructor parameter
     * name. Left unrejected it would reach reconstruct() as an ordinary
     * job failure and burn every retry on a payload that can never
     * succeed.
     */
    public function test_stored_args_rejects_an_integer_keyed_array(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"args"');

        QueueContract::storedArgs(['value']);
    }

    public function test_stored_args_rejects_a_non_array(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);

        QueueContract::storedArgs('not an array');
    }

    public function test_stored_metadata_reads_null_an_array_and_raw_json(): void
    {
        self::assertSame([], QueueContract::storedMetadata(null));
        self::assertSame([], QueueContract::storedMetadata([]));
        self::assertSame(['trace_id' => 'abc123'], QueueContract::storedMetadata(['trace_id' => 'abc123']));
        self::assertSame(['trace_id' => 'abc123'], QueueContract::storedMetadata('{"trace_id":"abc123"}'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStoredMetadata(): iterable
    {
        yield 'invalid JSON' => ['{not valid json'];
        yield 'an int value' => [['trace_id' => 42]];
        yield 'a nested array value' => [['trace_id' => ['abc']]];
        yield 'an integer key' => [[0 => 'abc']];
        yield 'an int' => [42];
    }

    /**
     * Trace propagation is entitled to assume a flat string-to-string
     * map, so a corrupted value is rejected rather than read as "no
     * metadata".
     */
    #[DataProvider('invalidStoredMetadata')]
    public function test_stored_metadata_rejects_anything_that_is_not_a_flat_string_map(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);

        QueueContract::storedMetadata($raw);
    }

    public function test_assert_field_present_accepts_a_present_key_holding_null(): void
    {
        QueueContract::assertFieldPresent(['maxAttempts' => null], 'maxAttempts');

        $this->addToAssertionCount(1);
    }

    public function test_assert_field_present_rejects_a_key_that_is_missing_entirely(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('missing entirely');

        QueueContract::assertFieldPresent(['class' => 'App\\Job'], 'maxAttempts');
    }

    public function test_settle_if_malformed_returns_the_decoded_value_and_never_settles_on_success(): void
    {
        $settled = false;

        $result = QueueContract::settleIfMalformed(
            'default',
            static fn (): string => 'a real decoded value',
            static function () use (&$settled): void {
                $settled = true;
            },
        );

        self::assertSame('a real decoded value', $result);
        self::assertFalse($settled);
    }

    public function test_settle_if_malformed_settles_and_reports_a_settled_malformed_job(): void
    {
        $settled = false;
        $failure = MalformedQueuedJobDataException::invalidJson('payload');

        try {
            QueueContract::settleIfMalformed(
                'high-priority',
                static function () use ($failure): never {
                    throw $failure;
                },
                static function () use (&$settled): void {
                    $settled = true;
                },
            );
            self::fail('Expected MalformedJobSettledException to be thrown.');
        } catch (MalformedJobSettledException $e) {
            self::assertTrue($settled, 'settle() must run before the settled exception is thrown.');
            self::assertSame('high-priority', $e->queue);
            self::assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{callable(): never}>
     */
    public static function nonMalformedFailures(): iterable
    {
        yield 'an exception' => [static function (): never {
            throw new RuntimeException('a programming defect, not a data problem');
        }];
        yield 'an error' => [static function (): never {
            throw new Error('Call to undefined method — a decoder defect, not a data problem.');
        }];
    }

    /**
     * Settling means deleting, which is only right when the data itself
     * is unusable. A defect in Kinetis's own decode path must leave the
     * message recoverable, so it propagates unwrapped and settle() never
     * runs.
     *
     * @param callable(): never $decode
     */
    #[DataProvider('nonMalformedFailures')]
    public function test_settle_if_malformed_does_not_catch_a_failure_that_is_not_about_the_data(callable $decode): void
    {
        $settled = false;

        try {
            QueueContract::settleIfMalformed(
                'default',
                $decode,
                static function () use (&$settled): void {
                    $settled = true;
                },
            );
            self::fail('Expected the original failure to propagate unwrapped.');
        } catch (MalformedJobSettledException) {
            self::fail('A failure that is not about the data must never be reported as a settled malformed job.');
        } catch (RuntimeException | Error) {
            self::assertFalse($settled);
        }
    }

    /**
     * A settlement that itself fails is a transport failure and must
     * surface as one, not be hidden behind the malformed-data outcome it
     * was trying to report.
     */
    public function test_settle_if_malformed_does_not_hide_a_settlement_failure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('settle() itself failed');

        QueueContract::settleIfMalformed(
            'default',
            static function (): never {
                throw MalformedQueuedJobDataException::invalidJson('payload');
            },
            static function (): never {
                throw new LogicException('settle() itself failed');
            },
        );
    }
}
