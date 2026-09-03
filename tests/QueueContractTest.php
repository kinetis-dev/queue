<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Queue\Exception\InvalidAttemptsException;
use Kinetis\Queue\Exception\InvalidDelaySecondsException;
use Kinetis\Queue\Exception\InvalidMaxAttemptsException;
use Kinetis\Queue\Exception\InvalidPopTimeoutException;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\MalformedQueuedJobDataException;
use Kinetis\Queue\QueueContract;
use Error;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class QueueContractTest extends TestCase
{
    public function test_a_non_empty_queue_name_is_accepted(): void
    {
        QueueContract::assertValidQueueName('default');

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_queue_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('must not be an empty string');

        QueueContract::assertValidQueueName('');
    }

    public function test_an_empty_queue_list_is_accepted_as_the_deliberate_nothing_to_check_case(): void
    {
        QueueContract::assertValidQueueList([]);

        $this->addToAssertionCount(1);
    }

    public function test_a_queue_list_with_distinct_names_is_accepted(): void
    {
        QueueContract::assertValidQueueList(['high', 'default', 'low']);

        $this->addToAssertionCount(1);
    }

    public function test_a_queue_list_containing_an_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('must not be an empty string');

        QueueContract::assertValidQueueList(['high', '']);
    }

    public function test_a_queue_list_with_a_duplicate_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('"default" appears more than once');

        QueueContract::assertValidQueueList(['default', 'high', 'default']);
    }

    public function test_a_zero_pop_timeout_is_accepted_as_unbounded(): void
    {
        QueueContract::assertValidPopTimeout(0);

        $this->addToAssertionCount(1);
    }

    public function test_a_positive_pop_timeout_is_accepted(): void
    {
        QueueContract::assertValidPopTimeout(30);

        $this->addToAssertionCount(1);
    }

    public function test_a_negative_pop_timeout_is_rejected(): void
    {
        $this->expectException(InvalidPopTimeoutException::class);
        $this->expectExceptionMessage('got -1');

        QueueContract::assertValidPopTimeout(-1);
    }

    public function test_assert_valid_pop_arguments_checks_both_timeout_and_queue_list(): void
    {
        $this->expectException(InvalidPopTimeoutException::class);

        QueueContract::assertValidPopArguments(-5, ['default']);
    }

    public function test_assert_valid_pop_arguments_checks_the_queue_list_when_the_timeout_is_valid(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('must not be an empty string');

        QueueContract::assertValidPopArguments(5, ['']);
    }

    public function test_assert_valid_pop_arguments_accepts_a_valid_combination(): void
    {
        QueueContract::assertValidPopArguments(5, ['high', 'default']);

        $this->addToAssertionCount(1);
    }

    /**
     * @return list<array{string}>
     */
    public static function validQueueNames(): array
    {
        return [
            ['default'],
            ['high-priority'],
            ['under_score'],
            ['UPPER-lower-123'],
            [str_repeat('a', 80)], // the real SQS-derived cap, exactly.
        ];
    }

    #[DataProvider('validQueueNames')]
    public function test_a_conforming_queue_name_is_accepted(string $queue): void
    {
        QueueContract::assertValidQueueName($queue);

        $this->addToAssertionCount(1);
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedQueueNames(): array
    {
        return [
            'contains a space' => ['has spaces'],
            'whitespace only' => ['   '],
            'contains a period' => ['high.default'],
            'contains a slash' => ['high/default'],
            'contains a colon' => ['high:default'],
            'contains a newline' => ["high\ndefault"],
            'contains a null byte' => ["high\0default"],
            'over the 80-character cap' => [str_repeat('a', 81)],
        ];
    }

    #[DataProvider('malformedQueueNames')]
    public function test_a_malformed_queue_name_is_rejected(string $queue): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('not a valid logical queue name');

        QueueContract::assertValidQueueName($queue);
    }

    public function test_a_zero_delay_is_accepted_as_immediate(): void
    {
        QueueContract::assertValidDelaySeconds(0);

        $this->addToAssertionCount(1);
    }

    public function test_a_positive_delay_is_accepted(): void
    {
        QueueContract::assertValidDelaySeconds(300);

        $this->addToAssertionCount(1);
    }

    public function test_a_negative_delay_is_rejected(): void
    {
        $this->expectException(InvalidDelaySecondsException::class);
        $this->expectExceptionMessage('got -1');

        QueueContract::assertValidDelaySeconds(-1);
    }

    public function test_a_null_max_attempts_is_accepted_as_deferring_to_the_worker_default(): void
    {
        QueueContract::assertValidMaxAttempts(null);

        $this->addToAssertionCount(1);
    }

    public function test_a_zero_max_attempts_is_accepted_as_no_retries(): void
    {
        QueueContract::assertValidMaxAttempts(0);

        $this->addToAssertionCount(1);
    }

    public function test_a_positive_max_attempts_is_accepted(): void
    {
        QueueContract::assertValidMaxAttempts(5);

        $this->addToAssertionCount(1);
    }

    public function test_a_negative_max_attempts_is_rejected(): void
    {
        $this->expectException(InvalidMaxAttemptsException::class);
        $this->expectExceptionMessage('got -1');

        QueueContract::assertValidMaxAttempts(-1);
    }

    public function test_an_attempts_value_of_one_is_accepted(): void
    {
        QueueContract::assertValidAttempts(1);

        $this->addToAssertionCount(1);
    }

    public function test_an_attempts_value_above_one_is_accepted(): void
    {
        QueueContract::assertValidAttempts(4);

        $this->addToAssertionCount(1);
    }

    public function test_an_attempts_value_of_zero_is_rejected(): void
    {
        $this->expectException(InvalidAttemptsException::class);
        $this->expectExceptionMessage('got 0');

        QueueContract::assertValidAttempts(0);
    }

    public function test_a_negative_attempts_value_is_rejected(): void
    {
        $this->expectException(InvalidAttemptsException::class);
        $this->expectExceptionMessage('got -1');

        QueueContract::assertValidAttempts(-1);
    }

    public function test_assert_valid_push_arguments_accepts_a_valid_combination(): void
    {
        QueueContract::assertValidPushArguments(60, 'default', 3);

        $this->addToAssertionCount(1);
    }

    public function test_assert_valid_push_arguments_accepts_the_all_defaults_combination(): void
    {
        QueueContract::assertValidPushArguments(0, 'default', null);

        $this->addToAssertionCount(1);
    }

    public function test_assert_valid_push_arguments_checks_delay_seconds(): void
    {
        $this->expectException(InvalidDelaySecondsException::class);

        QueueContract::assertValidPushArguments(-1, 'default', null);
    }

    public function test_assert_valid_push_arguments_checks_the_queue_name_when_delay_is_valid(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('must not be an empty string');

        QueueContract::assertValidPushArguments(0, '', null);
    }

    public function test_assert_valid_push_arguments_checks_max_attempts_when_delay_and_queue_are_valid(): void
    {
        $this->expectException(InvalidMaxAttemptsException::class);

        QueueContract::assertValidPushArguments(0, 'default', -1);
    }

    public function test_coerce_stored_integer_passes_a_real_int_through_unchanged(): void
    {
        self::assertSame(5, QueueContract::coerceStoredInteger(5, 'attempts'));
        self::assertSame(-1, QueueContract::coerceStoredInteger(-1, 'attempts'));
        self::assertSame(0, QueueContract::coerceStoredInteger(0, 'attempts'));
    }

    public function test_coerce_stored_integer_parses_a_clean_numeric_string(): void
    {
        self::assertSame(5, QueueContract::coerceStoredInteger('5', 'attempts'));
        self::assertSame(-1, QueueContract::coerceStoredInteger('-1', 'attempts'));
        // Deliberate, not incidental: plain "0" is the one canonical
        // zero every backend here ever actually writes, and it's the
        // exact counterpart to the "-0" rejection below — the contract
        // is "accept 0, reject its noncanonical signed form," not "the
        // regex happens to also match 0."
        self::assertSame(0, QueueContract::coerceStoredInteger('0', 'attempts'));
    }

    /**
     * The exact platform boundary, both directions, and both as native
     * ints and as their own decimal-string representation — the string
     * form is what actually exercises filter_var()'s own range check
     * (a native int never reaches it at all, see coerceStoredInteger()'s
     * own docblock), while the native-int cases confirm the boundary
     * itself is never mistaken for out-of-range.
     */
    public function test_coerce_stored_integer_accepts_the_exact_platform_boundary(): void
    {
        self::assertSame(PHP_INT_MAX, QueueContract::coerceStoredInteger(PHP_INT_MAX, 'attempts'));
        self::assertSame(PHP_INT_MIN, QueueContract::coerceStoredInteger(PHP_INT_MIN, 'attempts'));
        self::assertSame(PHP_INT_MAX, QueueContract::coerceStoredInteger((string) PHP_INT_MAX, 'attempts'));
        self::assertSame(PHP_INT_MIN, QueueContract::coerceStoredInteger((string) PHP_INT_MIN, 'attempts'));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function malformedStoredIntegers(): array
    {
        return [
            'non-numeric garbage' => ['garbage'],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'leading whitespace' => [' 5'],
            'trailing whitespace' => ['5 '],
            'leading plus sign' => ['+5'],
            'decimal point' => ['5.0'],
            'scientific notation' => ['5e2'],
            'hex-looking string' => ['0x5'],
            'leading zero' => ['007'],
            // filter_var(..., FILTER_VALIDATE_INT) accepts "-0" as plain
            // 0 on its own, confirmed directly rather than assumed — no
            // backend here ever writes a signed zero (a plain "0" always
            // represents one), so this is rejected explicitly rather
            // than silently inherited from that incidental behavior.
            'signed zero' => ['-0'],
            'a float' => [5.0],
            'a bool' => [true],
            'null' => [null],
            'an array' => [[5]],
            'an object' => [new stdClass()],
            // A syntactically valid decimal (matches the regex gate
            // outright) whose magnitude exceeds what this platform's
            // native int can represent — the reviewer's own reported
            // gap: a naive `(int)` cast clamps this to PHP_INT_MAX
            // rather than rejecting it.
            'positive overflow, far beyond the boundary' => ['999999999999999999999'],
            'negative overflow, far beyond the boundary' => ['-999999999999999999999'],
            // One past the real boundary on each side — the precise
            // edge the fix has to get right, not just the obviously
            // oversized case above.
            'one past PHP_INT_MAX' => [self::onePast(PHP_INT_MAX)],
            'one past PHP_INT_MIN' => [self::onePast(PHP_INT_MIN)],
        ];
    }

    /**
     * Builds a decimal string one magnitude-step past $boundary — for a
     * positive boundary, one more positive; for a negative one (only
     * PHP_INT_MIN in practice here), one more negative — via plain string
     * arithmetic on its last digit, deliberately not `$boundary + 1`
     * (which would itself silently overflow to a float before ever
     * reaching the string this test actually needs) and deliberately not
     * `abs($boundary)` either: PHP_INT_MIN's own magnitude is one greater
     * than PHP_INT_MAX, so `abs(PHP_INT_MIN)` itself overflows to a float,
     * confirmed directly rather than assumed safe — which would silently
     * produce a scientific-notation string for exactly that boundary,
     * making the "one past PHP_INT_MIN" case test nothing more than the
     * already-covered scientific-notation rejection instead of the real
     * boundary. `(string) $boundary` never has this problem — PHP's own
     * int-to-string conversion is always exact — so the sign is stripped
     * and restored via plain string operations instead.
     */
    private static function onePast(int $boundary): string
    {
        $stringBoundary = (string) $boundary;
        $isNegative = str_starts_with($stringBoundary, '-');

        $digits = str_split($isNegative ? substr($stringBoundary, 1) : $stringBoundary);
        $lastIndex = array_key_last($digits);
        $digits[$lastIndex] = (string) ((int) $digits[$lastIndex] + 1);

        $magnitude = implode('', $digits);

        return $isNegative ? "-{$magnitude}" : $magnitude;
    }

    #[DataProvider('malformedStoredIntegers')]
    public function test_coerce_stored_integer_rejects_anything_that_is_not_a_clean_integer(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"attempts"');

        QueueContract::coerceStoredInteger($raw, 'attempts');
    }

    /**
     * A corrupted or non-Kinetis-written stored value isn't necessarily
     * merely out of range — it could be arbitrarily long. Proves both
     * halves of the length gate at once: the huge string is rejected at
     * all (the length check runs before the regex ever gets a chance to
     * scan it), and — the actual point of this test — the exception's
     * own message never reflects the huge value back wholesale. A prior
     * version of MalformedQueuedJobDataException would have var_export()'d
     * the entire value into the message, turning one corrupted bookkeeping
     * field into an equally huge log line; this asserts that can't happen
     * regardless of how large the stored value actually is.
     */
    public function test_coerce_stored_integer_rejects_a_very_large_numeric_string_with_a_bounded_message(): void
    {
        $huge = str_repeat('9', 10_000);

        try {
            QueueContract::coerceStoredInteger($huge, 'attempts');
            self::fail('Expected MalformedQueuedJobDataException to be thrown.');
        } catch (MalformedQueuedJobDataException $e) {
            self::assertStringNotContainsString($huge, $e->getMessage());
            self::assertLessThan(500, strlen($e->getMessage()));
        }
    }

    /**
     * A large all-digit string (the case above) never needed escaping,
     * so it alone can't prove the diagnostic doesn't allocate an
     * escaped copy of the whole value before truncating — a real gap a
     * prior fix left open specifically for non-printable/binary data,
     * where every byte expands under addcslashes(). Repeated high bytes
     * are the worst case for that expansion (each escapes to a 4-byte
     * octal `\NNN` sequence), so this is deliberately not digits.
     */
    public function test_coerce_stored_integer_rejects_a_large_binary_string_with_a_bounded_message(): void
    {
        $rawLength = 5_000;
        $binary = str_repeat("\xFF", $rawLength);

        try {
            QueueContract::coerceStoredInteger($binary, 'attempts');
            self::fail('Expected MalformedQueuedJobDataException to be thrown.');
        } catch (MalformedQueuedJobDataException $e) {
            $message = $e->getMessage();

            // Single line: no raw non-printable byte (0xFF included)
            // survives unescaped into the message.
            self::assertStringNotContainsString("\xFF", $message);
            // Bounded: the message stays small regardless of the raw
            // value's own size.
            self::assertLessThan(500, strlen($message));
            // States the correct raw length — the original 5,000 bytes,
            // not however long the escaped preview happens to be.
            self::assertStringContainsString("{$rawLength} bytes total", $message);
            // Contains a useful escaped prefix (addcslashes()'s own
            // \NNN octal form for 0xFF is \377) without reproducing the
            // full value: a run long enough that it could only appear
            // if the whole 5,000-byte value had been escaped is absent.
            self::assertStringContainsString('\377', $message);
            self::assertStringNotContainsString(str_repeat('\377', 20), $message);
        }
    }

    /**
     * A raw newline byte is the concrete case the single-line guarantee
     * exists for — proven here at the same scale as the binary case
     * above, not just on a short string, since the fix that bounds the
     * escaping work must not reintroduce a raw newline by skipping a
     * byte the truncation point happens to land on.
     */
    public function test_coerce_stored_integer_rejects_a_large_string_containing_a_raw_newline_without_leaking_it(): void
    {
        $withEmbeddedNewline = "line one\nline two" . str_repeat('X', 5_000);

        try {
            QueueContract::coerceStoredInteger($withEmbeddedNewline, 'attempts');
            self::fail('Expected MalformedQueuedJobDataException to be thrown.');
        } catch (MalformedQueuedJobDataException $e) {
            $message = $e->getMessage();

            self::assertStringNotContainsString("\n", $message);
            self::assertStringContainsString('\n', $message);
            self::assertStringContainsString(strlen($withEmbeddedNewline) . ' bytes total', $message);
        }
    }

    public function test_an_empty_queue_name_prefix_is_accepted_as_no_prefix(): void
    {
        QueueContract::assertValidQueueNamePrefix('');

        $this->addToAssertionCount(1);
    }

    public function test_a_conforming_queue_name_prefix_is_accepted(): void
    {
        QueueContract::assertValidQueueNamePrefix('myapp-');

        $this->addToAssertionCount(1);
    }

    public function test_a_malformed_queue_name_prefix_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage('is not valid');

        QueueContract::assertValidQueueNamePrefix('my app.');
    }

    public function test_coerce_stored_json_array_decodes_a_valid_json_object(): void
    {
        self::assertSame(
            ['class' => 'App\\Job', 'args' => []],
            QueueContract::coerceStoredJsonArray('{"class":"App\\\\Job","args":[]}', 'payload'),
        );
    }

    public function test_coerce_stored_json_array_decodes_a_valid_json_list(): void
    {
        self::assertSame([1, 2, 3], QueueContract::coerceStoredJsonArray('[1,2,3]', 'payload'));
    }

    public function test_coerce_stored_json_array_rejects_invalid_json_syntax(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"payload"');
        $this->expectExceptionMessage('not valid JSON');

        QueueContract::coerceStoredJsonArray('{not valid json', 'payload');
    }

    /**
     * @return list<array{string}>
     */
    public static function jsonValuesThatArentArrays(): array
    {
        return [
            'a bare string' => ['"just a string"'],
            'a bare number' => ['5'],
            'a bare bool' => ['true'],
            'a bare null' => ['null'],
        ];
    }

    #[DataProvider('jsonValuesThatArentArrays')]
    public function test_coerce_stored_json_array_rejects_valid_json_that_is_not_an_array(string $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('unexpected shape');

        QueueContract::coerceStoredJsonArray($raw, 'payload');
    }

    public function test_coerce_stored_class_accepts_a_non_empty_string(): void
    {
        self::assertSame('App\\Job', QueueContract::coerceStoredClass('App\\Job'));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function invalidClassValues(): array
    {
        return [
            'missing (null)' => [null],
            'empty string' => [''],
            'an int' => [5],
            'an array' => [['App\\Job']],
        ];
    }

    #[DataProvider('invalidClassValues')]
    public function test_coerce_stored_class_rejects_anything_that_is_not_a_non_empty_string(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"class"');

        QueueContract::coerceStoredClass($raw);
    }

    public function test_coerce_stored_args_accepts_an_array(): void
    {
        self::assertSame(['foo' => 'bar'], QueueContract::coerceStoredArgs(['foo' => 'bar']));
        self::assertSame([], QueueContract::coerceStoredArgs([]));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function invalidArgsValues(): array
    {
        return [
            'missing (null)' => [null],
            'a string' => ['not an array'],
            'a number' => [5],
            'a bool' => [true],
            // A JSON *list* — "args": [value], no object keys at all —
            // decodes to a plain, integer-keyed PHP array, which
            // is_array() alone accepts cleanly. No real push() ever
            // writes this shape (JobSerializer::serialize() always uses
            // constructor parameter names as keys), and left unrejected
            // it reaches JobSerializer::reconstruct() as a raw,
            // incidental TypeError instead of the package-owned
            // exception this class exists to throw — see
            // coerceStoredArgs()'s own docblock for the concrete failure
            // mode that produces.
            'a non-empty JSON list (integer keys)' => [[0 => 'value']],
            'a mixed integer/string-keyed map' => [[0 => 'value', 'name' => 'other']],
        ];
    }

    #[DataProvider('invalidArgsValues')]
    public function test_coerce_stored_args_rejects_anything_that_is_not_an_array(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"args"');

        QueueContract::coerceStoredArgs($raw);
    }

    public function test_coerce_stored_metadata_treats_null_as_no_metadata(): void
    {
        self::assertSame([], QueueContract::coerceStoredMetadata(null));
    }

    public function test_coerce_stored_metadata_accepts_an_already_decoded_string_map(): void
    {
        self::assertSame(
            ['trace_id' => 'abc123'],
            QueueContract::coerceStoredMetadata(['trace_id' => 'abc123']),
        );
    }

    public function test_coerce_stored_metadata_accepts_an_empty_array(): void
    {
        self::assertSame([], QueueContract::coerceStoredMetadata([]));
    }

    public function test_coerce_stored_metadata_decodes_a_json_encoded_string_map(): void
    {
        self::assertSame(
            ['trace_id' => 'abc123'],
            QueueContract::coerceStoredMetadata('{"trace_id":"abc123"}'),
        );
    }

    public function test_coerce_stored_metadata_rejects_invalid_json_syntax(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"metadata"');

        QueueContract::coerceStoredMetadata('{not valid json');
    }

    /**
     * @return list<array{mixed}>
     */
    public static function invalidMetadataShapes(): array
    {
        return [
            'not an array at all' => [5],
            'a JSON array, not an object' => ['[1,2,3]'],
            'a non-string key' => [[0 => 'value']],
            'a non-string value' => [['trace_id' => 5]],
            'a nested array value' => [['trace_id' => ['abc123']]],
        ];
    }

    #[DataProvider('invalidMetadataShapes')]
    public function test_coerce_stored_metadata_rejects_anything_that_is_not_a_string_to_string_map(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"metadata"');

        QueueContract::coerceStoredMetadata($raw);
    }

    public function test_coerce_stored_completed_attempts_accepts_zero(): void
    {
        self::assertSame(0, QueueContract::coerceStoredCompletedAttempts(0, 'attempts'));
    }

    /**
     * The reviewer's own reported gap: a negative completed-attempts
     * count parses cleanly as an integer on its own (coerceStoredInteger()
     * has nothing to reject), but would produce a final attempts value
     * below QueuedJob's own 1-indexed floor once incremented — this
     * proves it's rejected here, via the package-owned exception
     * settleIfMalformed() actually catches, rather than left for
     * QueuedJob's own constructor to reject under a different type.
     */
    public function test_coerce_stored_completed_attempts_rejects_a_negative_value(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"attempts"');
        $this->expectExceptionMessage('out of bounds');

        QueueContract::coerceStoredCompletedAttempts(-1, 'attempts');
    }

    public function test_coerce_stored_attempts_accepts_the_exact_floor_of_one(): void
    {
        self::assertSame(1, QueueContract::coerceStoredAttempts(1, 'ApproximateReceiveCount'));
        self::assertSame(1, QueueContract::coerceStoredAttempts('1', 'ApproximateReceiveCount'));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function belowAttemptsFloor(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('belowAttemptsFloor')]
    public function test_coerce_stored_attempts_rejects_anything_below_the_floor_of_one(mixed $raw): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"ApproximateReceiveCount"');
        $this->expectExceptionMessage('out of bounds');

        QueueContract::coerceStoredAttempts($raw, 'ApproximateReceiveCount');
    }

    public function test_coerce_stored_max_attempts_accepts_null_as_no_override(): void
    {
        self::assertNull(QueueContract::coerceStoredMaxAttempts(null, 'maxAttempts'));
    }

    public function test_coerce_stored_max_attempts_accepts_zero(): void
    {
        self::assertSame(0, QueueContract::coerceStoredMaxAttempts(0, 'maxAttempts'));
    }

    public function test_coerce_stored_max_attempts_rejects_a_negative_value(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"maxAttempts"');
        $this->expectExceptionMessage('out of bounds');

        QueueContract::coerceStoredMaxAttempts(-1, 'maxAttempts');
    }

    public function test_assert_field_present_accepts_a_present_key_with_a_null_value(): void
    {
        QueueContract::assertFieldPresent(['maxAttempts' => null], 'maxAttempts');

        $this->addToAssertionCount(1);
    }

    public function test_assert_field_present_accepts_a_present_key_with_a_real_value(): void
    {
        QueueContract::assertFieldPresent(['maxAttempts' => 3], 'maxAttempts');

        $this->addToAssertionCount(1);
    }

    public function test_assert_field_present_rejects_a_key_that_is_missing_entirely(): void
    {
        $this->expectException(MalformedQueuedJobDataException::class);
        $this->expectExceptionMessage('"maxAttempts"');
        $this->expectExceptionMessage('missing entirely');

        QueueContract::assertFieldPresent(['class' => 'App\\Job'], 'maxAttempts');
    }

    public function test_settle_if_malformed_returns_the_decoded_value_and_never_calls_settle_on_success(): void
    {
        $settleCalled = false;

        $result = QueueContract::settleIfMalformed(
            'default',
            static fn (): string => 'a real decoded value',
            static function () use (&$settleCalled): void {
                $settleCalled = true;
            },
        );

        self::assertSame('a real decoded value', $result);
        self::assertFalse($settleCalled);
    }

    public function test_settle_if_malformed_settles_and_throws_malformed_job_settled_exception_on_decode_failure(): void
    {
        $settleCalled = false;
        $decodeFailure = MalformedQueuedJobDataException::invalidJson('payload', '{not valid');

        try {
            QueueContract::settleIfMalformed(
                'high-priority',
                static function () use ($decodeFailure): never {
                    throw $decodeFailure;
                },
                static function () use (&$settleCalled): void {
                    $settleCalled = true;
                },
            );
            self::fail('Expected MalformedJobSettledException to be thrown.');
        } catch (MalformedJobSettledException $e) {
            self::assertTrue($settleCalled, 'settle() must run before the settled exception is thrown.');
            self::assertSame('high-priority', $e->queue);
            self::assertSame($decodeFailure, $e->getPrevious());
            self::assertStringContainsString('high-priority', $e->getMessage());
        }
    }

    /**
     * $decode is caught narrowly on purpose — MalformedQueuedJobDataException
     * only, never a blanket Throwable: settling a message means
     * permanently deleting it, and that's only ever correct in response
     * to the data itself being unusable. A genuinely unexpected failure —
     * a programming defect in the decode closure, standing in here for a
     * TypeError from an undefined-method call or a similar framework
     * bug — must never be treated as "this message is malformed" and
     * must never trigger settle(): doing so would silently destroy a
     * possibly-perfectly-valid message because of a bug in Kinetis's own
     * code, not the stored data. It propagates completely unwrapped —
     * not even re-thrown as a different type — so the backend's own
     * native reservation-recovery semantics (a visibility timeout, a
     * connection-drop requeue) are what's left to handle it, the same as
     * for any other uncontained failure.
     */
    public function test_settle_if_malformed_does_not_catch_an_unexpected_non_malformed_data_throwable(): void
    {
        $settleCalled = false;

        try {
            QueueContract::settleIfMalformed(
                'default',
                static function (): never {
                    throw new RuntimeException('a programming defect, not a data problem');
                },
                static function () use (&$settleCalled): void {
                    $settleCalled = true;
                },
            );
            self::fail('Expected the original RuntimeException to propagate unwrapped.');
        } catch (MalformedJobSettledException) {
            self::fail('An unexpected non-malformed-data failure must never be reported as a settled malformed job.');
        } catch (RuntimeException $e) {
            self::assertSame('a programming defect, not a data problem', $e->getMessage());
            self::assertFalse($settleCalled, 'settle() must never run for a failure that is not the package-owned malformed-data exception.');
        }
    }

    /**
     * The reviewer's own named example, verbatim: an undefined-method
     * Error is a real, if hypothetical, PHP \Error — a separate class
     * hierarchy from \Exception entirely, not merely a different message
     * on the same RuntimeException already covered above. Proves the
     * narrowed catch (MalformedQueuedJobDataException, a \Exception
     * subtype) genuinely excludes \Error too, not just other \Exception
     * subtypes.
     */
    public function test_settle_if_malformed_does_not_catch_an_unexpected_error(): void
    {
        $settleCalled = false;

        try {
            QueueContract::settleIfMalformed(
                'default',
                static function (): never {
                    throw new Error('Call to undefined method — a real decoder defect, not a data problem.');
                },
                static function () use (&$settleCalled): void {
                    $settleCalled = true;
                },
            );
            self::fail('Expected the original Error to propagate unwrapped.');
        } catch (MalformedJobSettledException) {
            self::fail('An unexpected Error must never be reported as a settled malformed job.');
        } catch (Error $e) {
            self::assertStringContainsString('undefined method', $e->getMessage());
            self::assertFalse($settleCalled, 'settle() must never run for an Error, the same as for any other non-malformed-data failure.');
        }
    }

    /**
     * A settlement failure — the backend's own fail()-equivalent call
     * failing while trying to remove the poison message — must never be
     * hidden behind the more interesting-looking malformed-data outcome
     * it was trying to report. It propagates exactly as thrown, not
     * wrapped in MalformedJobSettledException.
     */
    public function test_settle_if_malformed_does_not_hide_a_settlement_failure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('settle() itself failed');

        QueueContract::settleIfMalformed(
            'default',
            static function (): never {
                throw MalformedQueuedJobDataException::invalidJson('payload', '{not valid');
            },
            static function (): never {
                throw new LogicException('settle() itself failed');
            },
        );
    }
}
