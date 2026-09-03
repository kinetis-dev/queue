<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Support;

use Kinetis\Queue\Exception\UnserializableJobException;
use Kinetis\Queue\Support\NormalizedPayload;
use Kinetis\Queue\Tests\Fixtures\Priority;
use Kinetis\Queue\Tests\Fixtures\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * NormalizedPayload has a public constructor — @internal is
 * documentation, not an access boundary — so nothing stops arbitrary
 * application code from constructing one directly with arbitrary data.
 * This proves the constructor itself is what closes that gap: every
 * unsupported/malformed category WireValue's own normalize()/
 * assertValidWireTree() reject is rejected here too, at construction
 * time, with the same UnserializableJobException a job's own
 * constructor argument would produce at push() time — never a silent
 * pass-through.
 */
final class NormalizedPayloadTest extends TestCase
{
    public function test_valid_already_normalized_data_constructs_successfully(): void
    {
        $payload = new NormalizedPayload([
            'name' => 'Alon',
            'priority' => ['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'high'],
            'items' => [1, 2, 3],
        ]);

        self::assertSame('Alon', $payload->wireArgs['name']);
    }

    public function test_a_raw_closure_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('closure');

        new NormalizedPayload(['x' => static fn () => null]);
    }

    public function test_a_raw_resource_is_rejected(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(UnserializableJobException::class);
            new NormalizedPayload(['x' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function test_a_raw_arbitrary_object_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => new stdClass()]);
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => NAN]);
    }

    public function test_infinity_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => INF]);
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => "\xB1\x31"]);
    }

    public function test_an_unwrapped_map_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);
        $this->expectExceptionMessage('unwrapped map');

        // A real map's own args must already be in the {$kinetisWireType:
        // "map", entries: {...}} shape normalize() itself produces — a
        // plain, never-normalized associative array is not valid
        // already-normalized data, whatever it happens to contain.
        new NormalizedPayload(['x' => ['name' => 'Alon']]);
    }

    public function test_a_sparse_array_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => [0 => 'a', 2 => 'b']]);
    }

    /**
     * The constructor's own type declares a string-keyed map, but PHP
     * never enforces that at runtime for a plain array — a dense list
     * (int keys from 0) reaching the strict `string $path` parameter
     * assertValidWireTree() takes would otherwise be a raw TypeError,
     * not the documented UnserializableJobException.
     */
    public function test_a_dense_list_at_the_top_level_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['first', 'second']);
    }

    public function test_sparse_integer_keys_at_the_top_level_are_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload([0 => 'a', 5 => 'b']);
    }

    public function test_mixed_string_and_integer_keys_at_the_top_level_are_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['name' => 'Alon', 0 => 'extra']);
    }

    /**
     * @return list<array{array<string, mixed>}>
     */
    public static function malformedTags(): array
    {
        return [
            'unrecognized wire-type value' => [['$kinetisWireType' => 'bogus']],
            'enum tag naming a nonexistent class' => [['$kinetisWireType' => 'enum', 'class' => 'App\\Enum\\LongGone', 'value' => 'x']],
            'enum tag naming a non-enum class' => [['$kinetisWireType' => 'enum', 'class' => stdClass::class, 'value' => 'x']],
            'enum tag whose value matches no case' => [['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'medium']],
            'enum tag with an extra field' => [['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 'high', 'extra' => 'x']],
            'enum tag missing a required field' => [['$kinetisWireType' => 'enum', 'class' => Priority::class]],
            // A string-backed enum given an int value: PHP generates
            // tryFrom(string $value) specifically for Priority, so an
            // int here would be a raw TypeError without the explicit
            // backing-type check.
            'enum tag with an int value against a string-backed enum' => [['$kinetisWireType' => 'enum', 'class' => Priority::class, 'value' => 1]],
            // The reverse direction: an int-backed enum given a string.
            'enum tag with a string value against an int-backed enum' => [['$kinetisWireType' => 'enum', 'class' => Severity::class, 'value' => 'high']],
            'datetime tag with an unparseable value' => [['$kinetisWireType' => 'datetime', 'value' => 'not a real date']],
            // Parseable, but not the canonical Y-m-d\TH:i:s.uP format
            // normalize() itself always produces — its meaning depends
            // on the worker's own clock/timezone, which normalize()
            // never does.
            'datetime tag with a parseable but non-canonical value' => [['$kinetisWireType' => 'datetime', 'value' => 'tomorrow']],
            'datetime tag missing its value' => [['$kinetisWireType' => 'datetime']],
            'map tag with a non-array entries' => [['$kinetisWireType' => 'map', 'entries' => 'not-an-array']],
            'map tag with an extra field' => [['$kinetisWireType' => 'map', 'entries' => [], 'extra' => 'x']],
            'normalizedPayload tag with a non-array wireArgs' => [['$kinetisWireType' => 'normalizedPayload', 'wireArgs' => 'not-an-array']],
        ];
    }

    #[DataProvider('malformedTags')]
    public function test_a_malformed_tag_is_rejected(array $tag): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => $tag]);
    }

    /**
     * The bypass this test suite exists to close: a valid-looking outer
     * shape whose nested content is unsupported must still be rejected —
     * the same recursive coverage WireValueTest already proves for
     * normalize() itself.
     */
    public function test_an_unsupported_value_nested_inside_a_valid_map_tag_is_rejected(): void
    {
        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload([
            'x' => ['$kinetisWireType' => 'map', 'entries' => ['inner' => new stdClass()]],
        ]);
    }

    public function test_a_recursive_array_is_rejected_rather_than_overflowing_the_stack(): void
    {
        $recursive = ['$kinetisWireType' => 'map', 'entries' => []];
        $recursive['entries']['self'] = &$recursive;

        $this->expectException(UnserializableJobException::class);

        new NormalizedPayload(['x' => $recursive]);
    }
}
