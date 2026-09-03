<?php

declare(strict_types=1);

namespace Kinetis\Queue\Support;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use Exception;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\Exception\UnserializableJobException;
use ReflectionEnum;
use ValueError;

/**
 * The portable wire-value contract JobSerializer::serialize()/
 * deserialize() enforce on every constructor argument — a job's
 * arguments cross a real process boundary (they're JSON-encoded by
 * RedisQueue/SqlQueue/SqsQueue/RabbitMqQueue, or held as-is by SyncQueue
 * and the in-memory test fixture), so a value that isn't portable across
 * that boundary must be rejected deterministically at push() time, not
 * discovered later as a worker-side crash or a silently different value.
 *
 * Supported: null, bool, int, a finite float, a valid-UTF-8 string, a
 * dense zero-based list (recursively), a string-keyed map (recursively —
 * every key must be a real string; PHP itself canonicalizes any
 * numeric-looking key to int on array construction, so any non-list
 * array containing an int key at all is inherently sparse or
 * non-sequential, never a map with an unlucky-looking key), a BackedEnum
 * case, and a DateTimeImmutable instance (exactly that class, not a
 * subclass — a subclass's own extra state has no representation here).
 * Everything else — a resource, a Closure, any other object, NAN/INF,
 * invalid UTF-8, a sparse or mixed-key array — is rejected at
 * normalize() time with a path naming where in the argument tree the
 * unsupported value was found, never the value itself (it may be
 * sensitive).
 *
 * An empty array is always treated as an empty list, never an empty
 * map — PHP's own array_is_list() already agrees ([] is a list), and
 * JSON has no way to tell an empty object apart from an empty array
 * once decoded back with assoc: true, so there is no ambiguity left to
 * resolve; a map is never empty by construction here.
 *
 * Collision-free by construction, not by convention: every non-list
 * array normalize() produces — an ordinary map, a BackedEnum tag, a
 * DateTimeImmutable tag, a NormalizedPayload tag — carries the same
 * RESERVED_TYPE_KEY discriminator, unconditionally. A raw map is never
 * left unwrapped on the strength of "this key is unlikely to appear by
 * accident" — every map, whatever its own content, is wrapped in
 * `{RESERVED_TYPE_KEY: "map", entries: {...}}` before normalize() ever
 * returns, so a caller's own map containing that literal key (even one
 * shaped exactly like a real tag) ends up nested one level inside
 * "entries" — never at the position restore() actually dispatches on —
 * and round-trips back to the identical map, key for key. restore()
 * requires the discriminator on every array-shaped value it's handed
 * (a list is recognized by array_is_list() instead, needing none) and
 * validates a recognized tag's field set exactly, rejecting extra or
 * missing fields rather than silently ignoring or defaulting them.
 *
 * A NormalizedPayload instance is publicly constructible, so its own
 * content is never simply trusted on the strength of its type alone —
 * its constructor calls assertValidWireTree() (below), the read-side
 * twin of normalize(): it walks a value confirming it is *already* in
 * normalize()'s own output shape (a scalar, a list, or a correctly
 * discriminated/exact-keyed tag) without reconstructing anything,
 * rejecting a raw Closure/resource/object, NAN/INF, invalid UTF-8, an
 * unwrapped map, or a malformed tag exactly as normalize() itself would
 * — so a NormalizedPayload can carry already-normalized data through
 * without normalize() re-walking it, but never carry unvalidated data
 * through instead.
 *
 * No byte of a map key — from an ordinary map or from within a
 * NormalizedPayload being validated — ever reaches an exception
 * message: a key is itself application-controlled payload data (a
 * lookup table keyed by a token, for instance), so every one is
 * rendered through renderKeySegment() first, which replaces it
 * entirely with a short, non-reversible fingerprint (a truncated
 * SHA-256 digest plus the key's own byte length) rather than an
 * escaped or truncated form of the key itself — escaping alone still
 * leaves a short key fully readable and a long one's own leading
 * bytes exposed. A #[Sensitive]-marked top-level argument goes
 * further still: its own subtree never reaches a nested path at all —
 * see JobSerializer::serialize()'s own catch for why.
 */
final class WireValue
{
    /**
     * Present on every non-list array normalize() ever produces — see
     * the class docblock's "Collision-free by construction" paragraph.
     */
    private const string RESERVED_TYPE_KEY = '$kinetisWireType';

    /**
     * A circuit breaker against a genuinely recursive structure (a PHP
     * array made self-referential via a by-reference assignment), not a
     * realistic ceiling on legitimate payload nesting — real job/event
     * arguments nest nowhere near this deep.
     */
    private const int MAX_DEPTH = 32;

    private const string AN_INSTANCE_OF = 'an instance of ';

    // Never instantiated — every method here is static.
    private function __construct() {}

    public static function normalize(mixed $value, string $class, string $path): mixed
    {
        return self::normalizeAt($value, $class, $path, 0);
    }

    public static function restore(mixed $value, string $class, string $path): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $restored = [];

            foreach ($value as $index => $item) {
                $restored[$index] = self::restore($item, $class, "{$path}[{$index}]");
            }

            return $restored;
        }

        // Every non-list array normalize() ever produces carries the
        // discriminator unconditionally — its absence here means this
        // payload was never written by this mechanism at all (a real
        // schema-drift case, not a value normalize() itself would ever
        // produce without it).
        if (!array_key_exists(self::RESERVED_TYPE_KEY, $value)) {
            throw JobReconstructionException::invalidWireValue(
                $class,
                $path,
                'a map missing its wire-type discriminator — this payload was not written by a compatible version',
            );
        }

        return self::restoreTagged($value, $class, $path);
    }

    /**
     * NormalizedPayload's own constructor calls this — the read-side
     * twin of normalize(), confirming $value is *already* in
     * normalize()'s own output shape rather than converting a raw PHP
     * value into it. Throws the identical UnserializableJobException
     * normalize() itself would, so a NormalizedPayload built from
     * unsupported/malformed data fails at construction time exactly
     * like an ordinary constructor argument fails at push() time — see
     * the class docblock's own paragraph on why this exists at all.
     */
    public static function assertValidWireTree(mixed $value, string $class, string $path): void
    {
        self::assertValidWireTreeAt($value, $class, $path, 0);
    }

    private static function assertValidWireTreeAt(mixed $value, string $class, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                'nested more than ' . self::MAX_DEPTH . ' levels deep (possibly a recursive structure)',
            );
        }

        match (true) {
            $value === null, is_bool($value), is_int($value) => null,
            // Reused directly: both already validate-or-throw and
            // return the value unchanged, which is exactly what's
            // needed here — normalize() and assertValidWireTree() agree
            // on what a valid scalar is by construction, not by two
            // separately-maintained rules.
            is_float($value) => self::normalizeFloat($value, $class, $path),
            is_string($value) => self::normalizeString($value, $class, $path),
            is_array($value) => self::assertValidWireArray($value, $class, $path, $depth),
            $value instanceof Closure => throw UnserializableJobException::forUnsupportedValue($class, $path, 'a closure'),
            is_resource($value) => throw UnserializableJobException::forUnsupportedValue($class, $path, 'a resource'),
            is_object($value) => throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                self::AN_INSTANCE_OF . $value::class . ' (raw objects are never already-normalized wire data)',
            ),
            default => throw UnserializableJobException::forUnsupportedValue($class, $path, 'an unsupported value'),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function assertValidWireArray(array $value, string $class, string $path, int $depth): void
    {
        if ($value === [] || array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::assertValidWireTreeAt($item, $class, "{$path}[{$index}]", $depth + 1);
            }

            return;
        }

        // Unlike normalizeArray(), this never wraps an unwrapped map —
        // it's validating that $value is *already* in normalize()'s own
        // output shape, and normalize() never produces a map-shaped
        // value without the discriminator.
        if (!array_key_exists(self::RESERVED_TYPE_KEY, $value)) {
            throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                'an unwrapped map (not a value normalize() itself would ever produce)',
            );
        }

        $type = $value[self::RESERVED_TYPE_KEY] ?? null;

        match ($type) {
            'map' => self::assertValidMapTag($value, $class, $path, $depth),
            'enum' => self::assertValidEnumTag($value, $class, $path),
            'datetime' => self::assertValidDateTimeTag($value, $class, $path),
            'normalizedPayload' => self::assertValidNormalizedPayloadTag($value, $class, $path, $depth),
            default => throw UnserializableJobException::forUnsupportedValue($class, $path, 'an unrecognized wire-type tag'),
        };
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertValidMapTag(array $value, string $class, string $path, int $depth): void
    {
        self::assertExactKeysOrUnserializable($value, [self::RESERVED_TYPE_KEY, 'entries'], $class, $path);

        $entries = $value['entries'];

        if (!is_array($entries)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a map tag whose entries are not themselves an array');
        }

        foreach ($entries as $key => $item) {
            if (!is_string($key)) {
                throw UnserializableJobException::forUnsupportedValue($class, $path, 'a map tag with a non-string entry key');
            }

            self::assertValidWireTreeAt($item, $class, "{$path}." . self::renderKeySegment($key), $depth + 1);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertValidEnumTag(array $value, string $class, string $path): void
    {
        self::assertExactKeysOrUnserializable($value, [self::RESERVED_TYPE_KEY, 'class', 'value'], $class, $path);

        $enumClass = $value['class'];
        $enumValue = $value['value'];

        if (!is_string($enumClass) || !enum_exists($enumClass) || !is_a($enumClass, BackedEnum::class, true)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'an enum tag naming a class that does not exist or is not a backed enum');
        }

        if (!is_int($enumValue) && !is_string($enumValue)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'an enum tag with a non-scalar backing value');
        }

        // Checked before ever calling tryFrom(): PHP generates
        // tryFrom()/from() typed against the enum's own backing type
        // specifically (e.g. tryFrom(string $value) for a string-backed
        // enum), not a permissive int|string union — an int handed to a
        // string-backed enum's tryFrom() throws a raw TypeError, not a
        // graceful null, the exact escape this check closes.
        if (!self::enumValueMatchesBackingType($enumClass, $enumValue)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, "an enum tag whose backing value type does not match {$enumClass}'s own backing type");
        }

        if ($enumClass::tryFrom($enumValue) === null) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'an enum tag whose value does not match any case');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertValidDateTimeTag(array $value, string $class, string $path): void
    {
        self::assertExactKeysOrUnserializable($value, [self::RESERVED_TYPE_KEY, 'value'], $class, $path);

        $raw = $value['value'];

        if (!is_string($raw)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a datetime tag with a non-string value');
        }

        if (self::tryParseCanonicalDateTime($raw) === null) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a datetime tag whose value is not the exact canonical format normalize() itself produces');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function assertValidNormalizedPayloadTag(array $value, string $class, string $path, int $depth): void
    {
        self::assertExactKeysOrUnserializable($value, [self::RESERVED_TYPE_KEY, 'wireArgs'], $class, $path);

        $wireArgs = $value['wireArgs'];

        if (!is_array($wireArgs)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a normalizedPayload tag whose wireArgs are not themselves an array');
        }

        foreach ($wireArgs as $key => $item) {
            if (!is_string($key)) {
                throw UnserializableJobException::forUnsupportedValue($class, $path, 'a normalizedPayload tag with a non-string wireArgs key');
            }

            self::assertValidWireTreeAt($item, $class, "{$path}." . self::renderKeySegment($key), $depth + 1);
        }
    }

    /**
     * A map key is application-controlled payload data — possibly a
     * secret itself (a lookup table keyed by a token) — so no byte of
     * it, not even an escaped or truncated prefix, ever reaches an
     * exception path. Escaping punctuation and bounding the length
     * (an earlier version of this method) is not redaction: any key at
     * or under the bound still appears in full, and a longer key still
     * leaks its own leading bytes. A short, non-reversible fingerprint
     * — a truncated SHA-256 digest, plus the key's own byte length —
     * stands in instead: two occurrences of the same key produce the
     * same fingerprint (useful for spotting "this failed on the same
     * key twice" while debugging), but nothing about the fingerprint or
     * the length lets the original key be reconstructed or partially
     * read. The fixed `<map-key ...>` format contains none of the
     * path syntax's own structural characters ('.', '[', ']'), so
     * there's nothing left to escape either.
     */
    private static function renderKeySegment(string $key): string
    {
        return sprintf('<map-key sha256:%s len:%d>', substr(hash('sha256', $key), 0, 16), strlen($key));
    }

    private static function normalizeAt(mixed $value, string $class, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                'nested more than ' . self::MAX_DEPTH . ' levels deep (possibly a recursive structure)',
            );
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => self::normalizeFloat($value, $class, $path),
            is_string($value) => self::normalizeString($value, $class, $path),
            is_array($value) => self::normalizeArray($value, $class, $path, $depth),
            $value instanceof BackedEnum => self::normalizeEnum($value),
            $value instanceof DateTimeImmutable => self::normalizeDateTime($value, $class, $path),
            $value instanceof NormalizedPayload => self::normalizeNormalizedPayload($value),
            $value instanceof Closure => throw UnserializableJobException::forUnsupportedValue($class, $path, 'a closure'),
            is_resource($value) => throw UnserializableJobException::forUnsupportedValue($class, $path, 'a resource'),
            is_object($value) => throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                self::AN_INSTANCE_OF . $value::class . ' (unsupported object type — only a BackedEnum case or a DateTimeImmutable instance is)',
            ),
            default => throw UnserializableJobException::forUnsupportedValue($class, $path, 'an unsupported value'),
        };
    }

    private static function normalizeFloat(float $value, string $class, string $path): float
    {
        if (!is_finite($value)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'NAN or INF, which JSON cannot represent');
        }

        return $value;
    }

    private static function normalizeString(string $value, string $class, string $path): string
    {
        // The '//u' PCRE UTF-8 mode is what's checked here, deliberately
        // not ext-mbstring's mb_check_encoding() — PCRE ships with core
        // PHP, mbstring doesn't, and this package must not gain a new
        // hard extension dependency just to validate a string.
        if (preg_match('//u', $value) !== 1) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'not valid UTF-8 (or contains raw binary data)');
        }

        return $value;
    }

    /**
     * A dense, zero-based list stays a plain array (a JSON array can
     * never collide with the map/tag shape below, which is always a
     * JSON object once decoded); a string-keyed map is always wrapped —
     * see the class docblock's "Collision-free by construction"
     * paragraph for why this never special-cases based on the map's
     * own content.
     *
     * @param array<array-key, mixed> $value
     * @return list<mixed>|array{'$kinetisWireType': 'map', entries: array<string, mixed>}
     */
    private static function normalizeArray(array $value, string $class, string $path, int $depth): array
    {
        if ($value === [] || array_is_list($value)) {
            $normalized = [];

            foreach ($value as $index => $item) {
                $normalized[$index] = self::normalizeAt($item, $class, "{$path}[{$index}]", $depth + 1);
            }

            return $normalized;
        }

        $entries = [];

        foreach ($value as $key => $item) {
            // A non-list array in PHP can only have this branch reached
            // with a genuine int key (PHP itself already canonicalizes
            // any numeric-looking string key to int on array
            // construction) — so this is exactly "a sparse or
            // non-sequential array," never a legitimate map with an
            // unlucky-looking string key.
            if (!is_string($key)) {
                throw UnserializableJobException::forUnsupportedValue(
                    $class,
                    $path,
                    'a sparse or non-sequential array (only a dense, zero-based list or a string-keyed map is portable)',
                );
            }

            $entries[$key] = self::normalizeAt($item, $class, "{$path}." . self::renderKeySegment($key), $depth + 1);
        }

        return [self::RESERVED_TYPE_KEY => 'map', 'entries' => $entries];
    }

    /**
     * @return array{'$kinetisWireType': 'enum', class: class-string, value: int|string}
     */
    private static function normalizeEnum(BackedEnum $value): array
    {
        return [
            self::RESERVED_TYPE_KEY => 'enum',
            'class' => $value::class,
            'value' => $value->value,
        ];
    }

    /**
     * @return array{'$kinetisWireType': 'datetime', value: string}
     */
    private static function normalizeDateTime(DateTimeImmutable $value, string $class, string $path): array
    {
        if ($value::class !== DateTimeImmutable::class) {
            throw UnserializableJobException::forUnsupportedValue(
                $class,
                $path,
                self::AN_INSTANCE_OF . $value::class . ' (a DateTimeImmutable subclass — only the exact base class is supported)',
            );
        }

        return [
            self::RESERVED_TYPE_KEY => 'datetime',
            // Microseconds ('u') plus a numeric UTC offset ('P') round
            // trips through DateTimeImmutable's own constructor losslessly
            // — this is the one format both directions agree on.
            'value' => $value->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * $value->wireArgs is trusted, not re-normalized — see
     * NormalizedPayload's own docblock for why.
     *
     * @return array{'$kinetisWireType': 'normalizedPayload', wireArgs: array<string, mixed>}
     */
    private static function normalizeNormalizedPayload(NormalizedPayload $value): array
    {
        return [self::RESERVED_TYPE_KEY => 'normalizedPayload', 'wireArgs' => $value->wireArgs];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function restoreTagged(array $value, string $class, string $path): mixed
    {
        $type = $value[self::RESERVED_TYPE_KEY] ?? null;

        return match ($type) {
            'map' => self::restoreMap($value, $class, $path),
            'enum' => self::restoreEnum($value, $class, $path),
            'datetime' => self::restoreDateTime($value, $class, $path),
            'normalizedPayload' => self::restoreNormalizedPayload($value, $class, $path),
            default => throw JobReconstructionException::invalidWireValue($class, $path, 'an unrecognized wire-type tag'),
        };
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function restoreMap(array $value, string $class, string $path): array
    {
        self::assertExactKeys($value, [self::RESERVED_TYPE_KEY, 'entries'], $class, $path);

        $entries = $value['entries'];

        if (!is_array($entries)) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'a map tag whose entries are not themselves an array');
        }

        $restored = [];

        foreach ($entries as $key => $item) {
            if (!is_string($key)) {
                throw JobReconstructionException::invalidWireValue($class, $path, 'a map tag with a non-string entry key');
            }

            $restored[$key] = self::restore($item, $class, "{$path}." . self::renderKeySegment($key));
        }

        return $restored;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function restoreEnum(array $value, string $class, string $path): BackedEnum
    {
        self::assertExactKeys($value, [self::RESERVED_TYPE_KEY, 'class', 'value'], $class, $path);

        $enumClass = $value['class'];
        $enumValue = $value['value'];

        if (!is_string($enumClass) || !enum_exists($enumClass) || !is_a($enumClass, BackedEnum::class, true)) {
            throw JobReconstructionException::invalidWireValue(
                $class,
                $path,
                'an enum tag naming a class that no longer exists or is not a backed enum',
            );
        }

        if (!is_int($enumValue) && !is_string($enumValue)) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'an enum tag with a non-scalar backing value');
        }

        // Checked before ever calling from(): see assertValidEnumTag()'s
        // own identical check for why a mismatch here would otherwise
        // throw a raw TypeError rather than ValueError.
        if (!self::enumValueMatchesBackingType($enumClass, $enumValue)) {
            throw JobReconstructionException::invalidWireValue($class, $path, "an enum tag whose backing value type does not match {$enumClass}'s own backing type");
        }

        try {
            /** @var BackedEnum */
            return $enumClass::from($enumValue);
        } catch (ValueError) {
            throw JobReconstructionException::invalidWireValue(
                $class,
                $path,
                "an enum tag whose value no longer matches any case of {$enumClass}",
            );
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function restoreDateTime(array $value, string $class, string $path): DateTimeImmutable
    {
        self::assertExactKeys($value, [self::RESERVED_TYPE_KEY, 'value'], $class, $path);

        $raw = $value['value'];

        if (!is_string($raw)) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'a datetime tag with a non-string value');
        }

        $parsed = self::tryParseCanonicalDateTime($raw);

        if ($parsed === null) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'a datetime tag whose value is not the exact canonical format normalize() itself produces');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function restoreNormalizedPayload(array $value, string $class, string $path): NormalizedPayload
    {
        self::assertExactKeys($value, [self::RESERVED_TYPE_KEY, 'wireArgs'], $class, $path);

        $wireArgs = $value['wireArgs'];

        if (!is_array($wireArgs)) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'a normalizedPayload tag whose wireArgs are not themselves an array');
        }

        /** @var array<string, mixed> $wireArgs */
        return new NormalizedPayload($wireArgs);
    }

    /**
     * Strict, not lenient: an unexpected extra field or a missing one is
     * exactly the shape of corruption a real payload could actually
     * exhibit after drift, and silently ignoring or defaulting it would
     * hide that rather than surface it.
     *
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private static function assertExactKeys(array $value, array $expectedKeys, string $class, string $path): void
    {
        if (!self::hasExactKeys($value, $expectedKeys)) {
            throw JobReconstructionException::invalidWireValue($class, $path, 'a wire-type tag with an unexpected set of fields');
        }
    }

    /**
     * assertValidWireTree()'s own counterpart to assertExactKeys() —
     * identical check, thrown as UnserializableJobException instead of
     * JobReconstructionException, matching assertValidWireTree()'s own
     * producer-side (not reconstruction-side) exception type throughout.
     *
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private static function assertExactKeysOrUnserializable(array $value, array $expectedKeys, string $class, string $path): void
    {
        if (!self::hasExactKeys($value, $expectedKeys)) {
            throw UnserializableJobException::forUnsupportedValue($class, $path, 'a wire-type tag with an unexpected set of fields');
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    /**
     * PHP generates a backed enum's own from()/tryFrom() typed against
     * that enum's *specific* backing type (e.g. `tryFrom(string
     * $value)` for a string-backed enum), never a permissive int|string
     * union — so an int handed to a string-backed enum's tryFrom() (or
     * vice versa) throws a raw TypeError rather than returning null or
     * throwing the documented ValueError. Checked via reflection before
     * either method is ever called, so that mismatch is turned into the
     * same domain exception every other rejection in this class already
     * produces, on both the validate-only and the reconstruct path.
     *
     * @param class-string<BackedEnum> $enumClass both call sites already
     *        narrow to this via enum_exists()/is_a() before reaching here
     */
    private static function enumValueMatchesBackingType(string $enumClass, int|string $value): bool
    {
        $backingType = new ReflectionEnum($enumClass)->getBackingType();

        return match ($backingType?->getName()) {
            'int' => is_int($value),
            'string' => is_string($value),
            default => false,
        };
    }

    /**
     * A datetime tag's own value must be exactly the format
     * normalizeDateTime() produces (`Y-m-d\TH:i:s.uP`), not merely
     * *some* string DateTimeImmutable's constructor happens to accept —
     * PHP's date parser also accepts relative strings ("tomorrow") and
     * many non-canonical absolute formats, and a relative string's
     * actual meaning depends on the worker's own clock/timezone at the
     * moment it's parsed, which is never what normalize() itself wrote
     * and is never reproducible on restore. Confirmed by parsing $raw
     * and then re-formatting the result with the identical format
     * string: only an exact match proves $raw was already canonical,
     * not merely parseable.
     */
    private static function tryParseCanonicalDateTime(string $raw): ?DateTimeImmutable
    {
        try {
            $parsed = new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }

        return $parsed->format('Y-m-d\TH:i:s.uP') === $raw ? $parsed : null;
    }
}
