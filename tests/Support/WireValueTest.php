<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Support;

use DateTimeImmutable;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\Support\NormalizedPayload;
use Kinetis\Queue\Support\WireValue;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The conformance matrix WireValue's own portable wire-value contract
 * has to hold for — every scalar boundary, every supported nested/rich
 * shape, and every deterministically-rejected one. JobSerializerTest
 * covers the same matrix one layer up, through serialize()/deserialize()
 * on a real job; this file is the direct, exhaustive proof of the
 * normalize()/restore() mechanism those methods delegate to.
 */
final class WireValueTest extends TestCase
{
    /**
     * @return list<array{mixed}>
     */
    public static function roundTrippableScalars(): array
    {
        return [
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'zero' => [0],
            'a negative int' => [-42],
            'PHP_INT_MAX' => [PHP_INT_MAX],
            'PHP_INT_MIN' => [PHP_INT_MIN],
            'a positive finite float' => [3.5],
            'a negative finite float' => [-3.5],
            'zero as a float' => [0.0],
            'an integral-valued float' => [4.0],
            'an empty string' => [''],
            'a plain ASCII string' => ['hello'],
            'a valid multi-byte UTF-8 string' => ['héllo — 世界 🎉'],
        ];
    }

    #[DataProvider('roundTrippableScalars')]
    public function test_a_supported_scalar_normalizes_and_restores_unchanged(mixed $value): void
    {
        $normalized = WireValue::normalize($value, 'Fixture\\Job', 'field');

        self::assertSame($value, $normalized);
        self::assertSame($value, WireValue::restore($normalized, 'Fixture\\Job', 'field'));
    }

    public function test_an_empty_array_normalizes_and_restores_as_an_empty_list(): void
    {
        $normalized = WireValue::normalize([], 'Fixture\\Job', 'items');

        self::assertSame([], $normalized);
        self::assertSame([], WireValue::restore($normalized, 'Fixture\\Job', 'items'));
    }

    public function test_a_dense_list_normalizes_and_restores_with_order_and_types_preserved(): void
    {
        $value = [1, 'two', 3.5, true, null];

        $normalized = WireValue::normalize($value, 'Fixture\\Job', 'items');

        self::assertSame($value, $normalized);
        self::assertSame($value, WireValue::restore($normalized, 'Fixture\\Job', 'items'));
    }

    /**
     * A map's own normalize() output is deliberately not its own input
     * unchanged — every map is wrapped in the {$kinetisWireType: "map",
     * entries: {...}} envelope unconditionally, which is what makes the
     * wire representation collision-free (see the class docblock's
     * "Collision-free by construction" paragraph, and the dedicated
     * collision tests below). What has to survive is the round trip.
     */
    public function test_a_string_keyed_map_normalizes_to_the_wrapped_shape_and_round_trips(): void
    {
        $value = ['name' => 'Alon', 'age' => 40, 'active' => true];

        $normalized = WireValue::normalize($value, 'Fixture\\Job', 'attrs');

        self::assertSame(
            ['$kinetisWireType' => 'map', 'entries' => $value],
            $normalized,
        );
        self::assertSame($value, WireValue::restore($normalized, 'Fixture\\Job', 'attrs'));
    }

    public function test_a_nested_list_of_maps_round_trips(): void
    {
        $value = [
            ['id' => 1, 'tags' => ['a', 'b']],
            ['id' => 2, 'tags' => []],
        ];

        $normalized = WireValue::normalize($value, 'Fixture\\Job', 'rows');

        self::assertSame($value, WireValue::restore($normalized, 'Fixture\\Job', 'rows'));
    }

    public function test_a_backed_enum_round_trips_to_an_equal_case(): void
    {
        $normalized = WireValue::normalize(Priority::High, 'Fixture\\Job', 'priority');

        self::assertSame(
            ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'high'],
            $normalized,
        );
        self::assertSame(Priority::High, WireValue::restore($normalized, 'Fixture\\Job', 'priority'));
    }

    public function test_a_backed_enum_nested_inside_a_list_round_trips(): void
    {
        $value = [Priority::High, Priority::Low];

        $normalized = WireValue::normalize($value, 'Fixture\\Job', 'priorities');
        $restored = WireValue::restore($normalized, 'Fixture\\Job', 'priorities');

        self::assertSame([Priority::High, Priority::Low], $restored);
    }

    public function test_a_datetimeimmutable_round_trips_to_an_equal_instant_including_microseconds(): void
    {
        $original = new DateTimeImmutable('2024-03-14T15:09:26.535897+02:00');

        $normalized = WireValue::normalize($original, 'Fixture\\Job', 'occurredAt');
        $restored = WireValue::restore($normalized, 'Fixture\\Job', 'occurredAt');

        self::assertInstanceOf(DateTimeImmutable::class, $restored);
        self::assertSame($original->format('Y-m-d\TH:i:s.uP'), $restored->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_a_datetimeimmutable_subclass_is_rejected(): void
    {
        $subclass = new class ('2024-01-01') extends DateTimeImmutable {};

        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('subclass');

        WireValue::normalize($subclass, 'Fixture\\Job', 'occurredAt');
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('"field"');

        WireValue::normalize(NAN, 'Fixture\\Job', 'field');
    }

    public function test_positive_infinity_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        WireValue::normalize(INF, 'Fixture\\Job', 'field');
    }

    public function test_negative_infinity_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        WireValue::normalize(-INF, 'Fixture\\Job', 'field');
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('UTF-8');

        WireValue::normalize("\xB1\x31", 'Fixture\\Job', 'field');
    }

    public function test_raw_binary_data_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        WireValue::normalize(random_bytes(16), 'Fixture\\Job', 'field');
    }

    public function test_a_resource_is_rejected(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(UnserializableJobException::class);
            $this->expectExceptionMessage('resource');

            WireValue::normalize($resource, 'Fixture\\Job', 'field');
        } finally {
            fclose($resource);
        }
    }

    public function test_a_closure_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('closure');

        WireValue::normalize(static fn () => null, 'Fixture\\Job', 'field');
    }

    public function test_an_arbitrary_object_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage(stdClass::class);

        WireValue::normalize(new stdClass(), 'Fixture\\Job', 'field');
    }

    public function test_a_sparse_array_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('sparse or non-sequential');

        WireValue::normalize([0 => 'a', 2 => 'b'], 'Fixture\\Job', 'field');
    }

    public function test_a_non_zero_based_int_keyed_array_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        WireValue::normalize([1 => 'a', 2 => 'b'], 'Fixture\\Job', 'field');
    }

    public function test_a_mixed_int_and_string_keyed_array_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        WireValue::normalize([0 => 'a', 'name' => 'b'], 'Fixture\\Job', 'field');
    }

    /**
     * A key that merely looks numeric ("0", "5") is not a distinguishable
     * case at all: PHP itself canonicalizes it to a real int key the
     * instant the array literal is built, before WireValue ever sees it
     * — so this array is identical, by the time it reaches normalize(),
     * to the sparse/non-sequential case above, and is rejected the same
     * way, not silently accepted as "a map with an unlucky key."
     */
    public function test_a_numeric_looking_string_key_is_indistinguishable_from_an_int_key_and_rejected(): void
    {
        $value = ['0' => 'a', '2' => 'b'];
        self::assertSame([0 => 'a', 2 => 'b'], $value, 'PHP already coerced both keys to int — confirming the premise, not testing WireValue itself');
        self::assertFalse(array_is_list($value), 'a single numeric-looking key alone would still be a valid one-element list — this needs a genuinely non-sequential case');

        $this->expectException(UnserializableJobException::class);

        WireValue::normalize($value, 'Fixture\\Job', 'field');
    }

    /**
     * The exact collision this class's own docblock claims cannot
     * happen: a raw application map that happens to be shaped exactly
     * like a real enum tag is never reinterpreted as one — it is wrapped
     * in the same {$kinetisWireType: "map", entries: {...}} envelope any
     * other map gets, so its own "$kinetisWireType"/"class"/"value" keys
     * end up nested one level inside "entries", never at the position
     * restore() actually dispatches on, and round-trips back to the
     * identical map, byte for byte.
     */
    public function test_a_raw_map_shaped_exactly_like_an_enum_tag_round_trips_as_the_same_map(): void
    {
        $rawMap = ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'high'];

        $normalized = WireValue::normalize($rawMap, 'Fixture\\Job', 'field');

        self::assertSame(
            ['$kinetisWireType' => 'map', 'entries' => $rawMap],
            $normalized,
            'the raw map is nested one level inside "entries", never mistaken for the tag it merely resembles',
        );
        self::assertSame($rawMap, WireValue::restore($normalized, 'Fixture\\Job', 'field'));
    }

    /**
     * The same proof against the datetime tag's own shape.
     */
    public function test_a_raw_map_shaped_exactly_like_a_datetime_tag_round_trips_as_the_same_map(): void
    {
        $rawMap = ['$kinetisWireType' => 'datetime', 'value' => '2024-01-01T00:00:00.000000+00:00'];

        $normalized = WireValue::normalize($rawMap, 'Fixture\\Job', 'field');
        $restored = WireValue::restore($normalized, 'Fixture\\Job', 'field');

        self::assertSame($rawMap, $restored);
        self::assertNotInstanceOf(DateTimeImmutable::class, $restored, 'this is a raw map the caller wrote, never actually a DateTimeImmutable');
    }

    /**
     * And against an entirely unrecognized tag name — a raw map is never
     * required to look like anything WireValue itself would produce to
     * round-trip correctly.
     */
    public function test_a_raw_map_with_an_unrecognized_wire_type_value_round_trips_as_the_same_map(): void
    {
        $rawMap = ['$kinetisWireType' => 'something-a-user-wrote', 'foo' => 'bar'];

        $normalized = WireValue::normalize($rawMap, 'Fixture\\Job', 'field');

        self::assertSame($rawMap, WireValue::restore($normalized, 'Fixture\\Job', 'field'));
    }

    /**
     * The bypass this class's own docblock rules out: a raw map's own
     * unsupported nested value, however the map's other keys happen to
     * be named, is still caught by the ordinary recursive walk — the
     * wrapping envelope adds a level of nesting, it never skips
     * validating what's inside it.
     */
    public function test_an_unsupported_nested_value_behind_a_colliding_key_is_still_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        // The offending key ("value") is itself rendered as a
        // fingerprint, not raw — this test's own point is that the
        // rejection still happens at all, one level deeper than the
        // colliding key.
        $this->expectExceptionMessage('"field.<map-key');

        WireValue::normalize(
            ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => new stdClass()],
            'Fixture\\Job',
            'field',
        );
    }

    /**
     * NormalizedPayload is the one value normalize() recognizes by a
     * real PHP type rather than array content — but its own
     * constructor already validated $wireArgs (see the class's own
     * dedicated tests below), so normalize() genuinely never re-walks
     * or re-validates it a second time here; it only embeds the
     * already-trusted result.
     */
    public function test_a_normalizedpayload_embeds_its_wire_args_unmodified(): void
    {
        $wireArgs = ['already' => 'normalized', 'nested' => ['$kinetisWireType' => 'map', 'entries' => ['x' => 1]]];

        $normalized = WireValue::normalize(new NormalizedPayload($wireArgs), 'Fixture\\Job', 'field');

        self::assertSame(
            ['$kinetisWireType' => 'normalizedPayload', 'wireArgs' => $wireArgs],
            $normalized,
        );
    }

    public function test_restoring_a_normalizedpayload_tag_produces_a_normalizedpayload_instance(): void
    {
        $wireArgs = ['message' => 'hello'];

        $restored = WireValue::restore(
            ['$kinetisWireType' => 'normalizedPayload', 'wireArgs' => $wireArgs],
            'Fixture\\Job',
            'field',
        );

        self::assertEquals(new NormalizedPayload($wireArgs), $restored);
    }

    /**
     * @return list<array{array<string, mixed>}>
     */
    public static function tagsWithAnUnexpectedFieldSet(): array
    {
        return [
            'enum tag with an extra field' => [['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'high', 'extra' => 'x']],
            'enum tag missing a required field' => [['$kinetisWireType' => 'enum', 'class' => Priority::class]],
            'datetime tag with an extra field' => [['$kinetisWireType' => 'datetime', 'value' => '2024-01-01T00:00:00.000000+00:00', 'extra' => 'x']],
            'datetime tag missing its value' => [['$kinetisWireType' => 'datetime']],
            'map tag with an extra field' => [['$kinetisWireType' => 'map', 'entries' => [], 'extra' => 'x']],
            'map tag missing entries' => [['$kinetisWireType' => 'map']],
            'normalizedPayload tag with an extra field' => [['$kinetisWireType' => 'normalizedPayload', 'wireArgs' => [], 'extra' => 'x']],
            'normalizedPayload tag missing wireArgs' => [['$kinetisWireType' => 'normalizedPayload']],
        ];
    }

    /**
     * Strict, not lenient: an unexpected extra field is exactly the
     * shape of corruption a real payload could exhibit after schema
     * drift, and silently ignoring it would hide that rather than
     * surface it — the same reasoning a missing required field already
     * gets.
     */
    #[DataProvider('tagsWithAnUnexpectedFieldSet')]
    public function test_restore_rejects_a_tag_with_an_unexpected_field_set(array $tag): void
    {
        $this->expectException(JobReconstructionException::class);

        WireValue::restore($tag, 'Fixture\\Job', 'field');
    }

    public function test_restore_rejects_a_map_shaped_value_missing_the_discriminator(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('discriminator');

        // Never a value normalize() itself would produce — every map
        // it writes carries the discriminator unconditionally. This is
        // what a payload written by an incompatible version looks like.
        WireValue::restore(['name' => 'Alon'], 'Fixture\\Job', 'field');
    }

    public function test_a_recursive_array_is_rejected_rather_than_overflowing_the_stack(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('recursive');

        WireValue::normalize($recursive, 'Fixture\\Job', 'field');
    }

    public function test_exception_messages_never_include_the_actual_rejected_value(): void
    {
        try {
            // The Closure itself is what's rejected, never anything it
            // might return if called — normalize() never calls it.
            WireValue::normalize(static fn () => 'a-genuinely-secret-token-value', 'Fixture\\Job', 'field');
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException $e) {
            self::assertStringNotContainsString('a-genuinely-secret-token-value', $e->getMessage());
        }
    }

    /**
     * A map key is application-controlled payload data too — a lookup
     * table keyed by a token, for instance — so no byte of it, not even
     * a length-bounded prefix, ever reaches an exception path (see
     * renderKeySegment()'s own docblock: escaping and truncating alone,
     * an earlier version of this mechanism, is not redaction — a key at
     * or under the length bound would still appear in full). This is
     * the general, always-on protection; a #[Sensitive]-marked argument
     * gets the stronger one (its whole subtree redacted) — see
     * JobSerializerTest for that.
     *
     * @return list<array{string}>
     */
    public static function mapKeysThatMustNeverLeak(): array
    {
        return [
            'a short secret-shaped token' => ['sk_live_abc123XYZ'],
            'a key at exactly the old 64-byte truncation bound' => [str_repeat('a', 64)],
            'a key well past the old 64-byte truncation bound' => [str_repeat('a-genuinely-secret-token-value-', 5)],
            'a multi-byte key crossing the old byte-truncation boundary' => [str_repeat('héllo — 世界 🎉', 6)],
            'a key containing path syntax characters' => ['a.b[0]'],
            'a key containing a control character' => ["line\nbreak"],
        ];
    }

    #[DataProvider('mapKeysThatMustNeverLeak')]
    public function test_no_meaningful_prefix_or_subsequence_of_a_map_key_ever_leaks(string $key): void
    {
        try {
            WireValue::normalize([$key => new stdClass()], 'Fixture\\Job', 'field');
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException $e) {
            $message = $e->getMessage();

            self::assertStringNotContainsString($key, $message);

            // Not just "the whole key is absent" — no meaningful
            // subsequence of it either, checked in fixed-size windows
            // long enough to be a real fragment, short enough that a
            // false-positive substring match is implausible.
            $windowSize = 8;

            for ($offset = 0; $offset + $windowSize <= strlen($key); $offset += 1) {
                self::assertStringNotContainsString(substr($key, $offset, $windowSize), $message);
            }

            self::assertMatchesRegularExpression('/<map-key sha256:[0-9a-f]{16} len:\d+>/', $message);
        }
    }

    public function test_a_rendered_map_key_is_valid_utf8_even_when_the_original_key_is_not(): void
    {
        try {
            WireValue::normalize(["\xB1\x31" => new stdClass()], 'Fixture\\Job', 'field');
            self::fail('Expected UnserializableJobException.');
        } catch (UnserializableJobException $e) {
            // preg_match('//u', ...) is exactly normalizeString()'s own
            // UTF-8 check — reused here as the test's own assertion that
            // the rendered path segment, unlike the original key, is
            // safe to embed anywhere UTF-8 is expected (a log line, in
            // particular).
            self::assertSame(1, preg_match('//u', $e->getMessage()));
        }
    }

    public function test_the_same_key_renders_identically_and_different_keys_render_differently(): void
    {
        $renderedA = $this->renderedKeySegmentFor('same-key');
        $renderedB = $this->renderedKeySegmentFor('same-key');
        $renderedC = $this->renderedKeySegmentFor('a-different-key');

        self::assertSame($renderedA, $renderedB, 'deterministic — the same key must fingerprint the same way twice, useful for spotting a repeat failure while debugging');
        self::assertNotSame($renderedA, $renderedC);
    }

    private function renderedKeySegmentFor(string $key): string
    {
        try {
            WireValue::normalize([$key => new stdClass()], 'Fixture\\Job', 'field');
        } catch (UnserializableJobException $e) {
            preg_match('/field\.(<map-key sha256:[0-9a-f]{16} len:\d+>)/', $e->getMessage(), $matches);

            return $matches[1];
        }

        self::fail('Expected UnserializableJobException.');
    }

    public function test_restore_rejects_an_unrecognized_wire_type_tag(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('unrecognized wire-type tag');

        WireValue::restore(['$kinetisWireType' => 'not-a-real-type'], 'Fixture\\Job', 'field');
    }

    public function test_restore_rejects_an_enum_tag_naming_a_class_that_no_longer_exists(): void
    {
        $this->expectException(JobReconstructionException::class);

        WireValue::restore(
            ['$kinetisWireType' => 'enum', 'class' => 'App\\Enum\\LongGone', 'value' => 'x'],
            'Fixture\\Job',
            'field',
        );
    }

    public function test_restore_rejects_an_enum_tag_naming_a_class_that_is_not_a_backed_enum(): void
    {
        $this->expectException(JobReconstructionException::class);

        WireValue::restore(
            ['$kinetisWireType' => 'enum', 'class' => stdClass::class, 'value' => 'x'],
            'Fixture\\Job',
            'field',
        );
    }

    public function test_restore_rejects_an_enum_tag_whose_value_matches_no_case(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('no longer matches any case');

        WireValue::restore(
            ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'medium'],
            'Fixture\\Job',
            'field',
        );
    }

    /**
     * Priority::tryFrom()/from() are generated typed against string
     * specifically — checked before either is ever called, since an int
     * argument there would otherwise be a raw TypeError, not the
     * documented JobReconstructionException.
     */
    public function test_restore_rejects_an_enum_tag_with_an_int_value_against_a_string_backed_enum(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('backing value type');

        WireValue::restore(
            ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 1],
            'Fixture\\Job',
            'field',
        );
    }

    /**
     * The reverse direction, against Severity — a real int-backed enum
     * fixture, since Priority alone can't exercise this half.
     */
    public function test_restore_rejects_an_enum_tag_with_a_string_value_against_an_int_backed_enum(): void
    {
        $this->expectException(JobReconstructionException::class);
        $this->expectExceptionMessage('backing value type');

        WireValue::restore(
            ['$kinetisWireType' => 'enum', 'class' => Severity::class, 'value' => 'high'],
            'Fixture\\Job',
            'field',
        );
    }

    public function test_an_int_backed_enum_round_trips_to_an_equal_case(): void
    {
        $normalized = WireValue::normalize(Severity::Critical, 'Fixture\\Job', 'severity');

        self::assertSame(
            ['$kinetisWireType' => 'enum', 'class' => Severity::class, 'value' => 9],
            $normalized,
        );
        self::assertSame(Severity::Critical, WireValue::restore($normalized, 'Fixture\\Job', 'severity'));
    }

    public function test_restore_rejects_a_datetime_tag_with_an_unparseable_value(): void
    {
        $this->expectException(JobReconstructionException::class);

        WireValue::restore(
            ['$kinetisWireType' => 'datetime', 'value' => 'not a real date at all'],
            'Fixture\\Job',
            'field',
        );
    }

    /**
     * "tomorrow" is genuinely parseable by DateTimeImmutable's
     * constructor, but its meaning depends on the worker's own clock and
     * timezone at the moment it's parsed — never what normalize() itself
     * wrote, and never reproducible on restore. Only the exact canonical
     * format normalize() produces (Y-m-d\TH:i:s.uP) is accepted; a merely
     * parseable string is rejected the same as a genuinely unparseable
     * one.
     */
    public function test_restore_rejects_a_datetime_tag_with_a_parseable_but_non_canonical_value(): void
    {
        $this->expectException(JobReconstructionException::class);

        WireValue::restore(
            ['$kinetisWireType' => 'datetime', 'value' => 'tomorrow'],
            'Fixture\\Job',
            'field',
        );
    }

    /**
     * The mechanism every real backend's own json_encode()/json_decode()
     * call ultimately relies on: normalize() only ever produces values
     * that survive that exact round trip losslessly, given
     * JSON_PRESERVE_ZERO_FRACTION (needed specifically for an
     * integral-valued float — see each backend's own encode call for
     * why). This is what proves the wire contract holds independent of
     * which backend's own envelope shape wraps it.
     */
    public function test_a_normalized_value_survives_the_real_json_envelope_round_trip(): void
    {
        $original = [
            'flag' => true,
            'count' => 4,
            'ratio' => 4.0,
            'label' => 'héllo — 世界',
            'tags' => ['a', 'b', 'c'],
            'nested' => ['id' => 1, 'children' => [['id' => 2, 'children' => []]]],
            'priority' => Priority::High,
            'occurredAt' => new DateTimeImmutable('2024-03-14T15:09:26.535897+00:00'),
        ];

        $normalized = [];

        foreach ($original as $key => $value) {
            $normalized[$key] = WireValue::normalize($value, 'Fixture\\Job', $key);
        }

        $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $restored = [];

        foreach ($decoded as $key => $value) {
            $restored[$key] = WireValue::restore($value, 'Fixture\\Job', (string) $key);
        }

        // assertEquals, not assertSame: restore() builds a genuinely new
        // DateTimeImmutable instance, never the original object
        // reference — everything else in $original is either a scalar,
        // a plain array, or a BackedEnum case (PHP enum cases are
        // canonical singletons, so those would still be === identical).
        self::assertEquals($original, $restored);
    }
}
