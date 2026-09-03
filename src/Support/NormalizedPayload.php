<?php

declare(strict_types=1);

namespace Kinetis\Queue\Support;

use Kinetis\Queue\Exception\UnserializableJobException;

/**
 * Wraps an args array that is *already* wire-safe — the direct output of
 * an earlier JobSerializer::serialize() call — so it can be carried as
 * one constructor argument's value without WireValue::normalize()
 * re-walking (and re-validating, redundantly) its own contents.
 *
 * Exists specifically for InvokeListenerJob::$eventArgs: QueuedListenerInvoker
 * already normalizes an event's own constructor arguments once, via
 * serialize(); InvokeListenerJob then carries that result as one of its
 * *own* constructor arguments, and InvokeListenerJob itself gets
 * serialized a second time — as the actual Job pushed onto the queue.
 * Without this wrapper, that second pass would either re-normalize
 * already-normalized data (harmless in isolation, but pointless work)
 * or — if WireValue tried to recognize "already normalized" from the
 * array's own shape instead of a real type — become a genuine collision
 * hazard: a raw, ordinary constructor argument that happens to look like
 * already-normalized wire data would be silently misinterpreted. A
 * NormalizedPayload instance is never something a raw PHP array can be
 * mistaken for — WireValue checks `instanceof`, not array contents — so
 * that hazard cannot arise regardless of what an ordinary argument's own
 * data happens to contain.
 *
 * `@internal` is documentation, not an access boundary — this class has
 * a public constructor, so nothing stops arbitrary application code from
 * constructing one directly with arbitrary data. $wireArgs is therefore
 * genuinely validated here, not merely trusted: the constructor calls
 * WireValue::assertValidWireTree(), which walks $wireArgs confirming
 * every value is already in normalize()'s own output shape (throwing
 * the identical UnserializableJobException an ordinary unsupported
 * constructor argument would, at push() time, if not) — the same
 * producer-side guarantee every other constructor argument gets,
 * whether or not the caller actually is kinetis/queue's own
 * QueuedListenerInvoker.
 *
 * $wireArgs' own top-level keys are checked too, not just their
 * values: the constructor's own type declares a string-keyed map, but
 * PHP never enforces that at runtime for a plain array — a caller
 * passing a dense list, a sparse array, or a mix of int and string
 * keys would otherwise reach assertValidWireTree()'s own strictly-typed
 * `string $path` parameter and fail with a raw TypeError instead of the
 * documented UnserializableJobException.
 */
final readonly class NormalizedPayload
{
    /**
     * @param array<array-key, mixed> $wireArgs deliberately not typed
     *        array<string, mixed> here: that's the *intended* shape, but
     *        PHP never enforces it for a plain array at runtime, and the
     *        constructor's own is_string() check right below exists
     *        specifically to catch a caller that violates it — a
     *        narrower docblock type would make PHPStan treat that check
     *        as dead code instead of the real guard it is.
     */
    public function __construct(
        public array $wireArgs,
    ) {
        foreach ($this->wireArgs as $key => $value) {
            if (!is_string($key)) {
                throw UnserializableJobException::forUnsupportedValue(
                    self::class,
                    (string) $key,
                    'a non-string top-level key (NormalizedPayload\'s own wireArgs must be a string-keyed map, matching what JobSerializer::serialize() itself always produces)',
                );
            }

            WireValue::assertValidWireTree($value, self::class, $key);
        }
    }
}
