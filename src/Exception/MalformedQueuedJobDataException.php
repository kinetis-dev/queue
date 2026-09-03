<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * A durable backend's own pop() decoder found stored data it couldn't
 * turn into a QueuedJob — a JSON payload edited by hand, a database
 * column populated some other way, an AMQP header set by a non-Kinetis
 * publisher. This covers every shape that failure can take: the wire
 * body itself isn't valid JSON (invalidJson()), a decoded value isn't
 * the object/array/string/etc. shape a field needs (invalidShape(), used
 * for a missing or wrong-typed `class`/`args`/`metadata` field as well
 * as a non-array decoded envelope), or a stored `attempts`/`maxAttempts`
 * value isn't a clean integer (notAnInteger()/notIncrementable()). The
 * queue is trusted infrastructure on the database's own tier, not an
 * input boundary (the same threat model JobReconstructionException's own
 * docblock already states for a job's class/constructor arguments) —
 * this exists so genuinely corrupted bookkeeping data becomes one
 * stable, catchable type naming exactly which field and value were
 * unusable, instead of a lossy `(int)` cast silently turning a
 * non-numeric string into 0 (a value QueueWorker's own exhaustion check
 * would then treat as perfectly legitimate), a raw JsonException/
 * TypeError escaping with no context, or a PHP notice for an undefined
 * array key.
 */
final class MalformedQueuedJobDataException extends RuntimeException
{
    /**
     * The raw value's own preview never exceeds this many bytes,
     * regardless of the real value's size — a bookkeeping field with a
     * corrupted, arbitrarily large stored value must never be reflected
     * wholesale into an exception message (and, through it, a log line):
     * a bound here is what actually closes that, not merely reduces it.
     */
    private const int MAX_PREVIEW_LENGTH = 60;

    public static function notAnInteger(string $field, mixed $raw): self
    {
        $type = get_debug_type($raw);

        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value ({$type}, " . self::preview($raw) . ') is not a valid '
            . 'integer — the durable storage this job was read from may be corrupted.',
        );
    }

    /**
     * An array or an object is reported by type alone — its own contents
     * aren't useful for diagnosing "why isn't this an integer" the way a
     * scalar's actual value is, and var_export()-ing an arbitrarily large
     * array/object would reintroduce the exact unbounded-message problem
     * this class exists to close for a string. A non-string scalar
     * (including null, whose own var_export() is always the short, fixed
     * "NULL") is bounded to MAX_PREVIEW_LENGTH bytes via a plain
     * var_export() + substr() — safe as a whole-value operation because
     * an int/float/bool/null's own natural string representation is
     * always short and never attacker-influenced in length the way a
     * stored string's raw byte count is.
     *
     * A string is handled differently, and deliberately not by escaping
     * the whole value up front and truncating the *result* afterward: a
     * corrupted or hostile stored value could be arbitrarily large, and
     * addcslashes() escaping the entire raw value first would still
     * allocate and scan a string up to four times its own size — the
     * exact resource cost this bound exists to close — even though only
     * MAX_PREVIEW_LENGTH bytes of the result are ever shown. Instead,
     * escapedBytePreview() below escapes one raw byte at a time and
     * stops as soon as the running escaped total would exceed
     * MAX_PREVIEW_LENGTH — so this method never scans, escapes, or
     * allocates more than roughly MAX_PREVIEW_LENGTH raw bytes of the
     * stored value, regardless of how large that value actually is.
     * Every non-printable byte (including a raw newline, which could
     * otherwise let a malformed value inject a fake-looking extra log
     * line) is escaped into a visible two- or four-character sequence
     * (addcslashes()'s own
     * `\n`/`\t`/... and `\NNN` octal forms, confirmed directly — never
     * a `\xNN` hex form), so the preview is always one safe, single-line,
     * printable string regardless of what garbage bytes the raw value
     * actually contains, and never a token cut in half mid-escape, since
     * each byte's escaped form is only ever appended to the preview as
     * one complete, atomic unit.
     */
    private static function preview(mixed $raw): string
    {
        if (\is_array($raw) || \is_object($raw)) {
            return 'no preview for an array/object value';
        }

        if (!\is_string($raw)) {
            $shown = \var_export($raw, true);

            if (\strlen($shown) <= self::MAX_PREVIEW_LENGTH) {
                return "'{$shown}'";
            }

            return "'" . \substr($shown, 0, self::MAX_PREVIEW_LENGTH) . "'...(truncated, " . \strlen($shown) . ' bytes total)';
        }

        [$shown, $truncated] = self::escapedBytePreview($raw);

        if (!$truncated) {
            return "'{$shown}'";
        }

        // strlen() is an O(1) length read on a PHP string (its length is
        // stored, never scanned for), so reporting the real raw byte
        // count here costs nothing extra regardless of $raw's own size
        // — and, unlike $shown's own length, it's the true original
        // length rather than however long the (bounded) escaped form
        // happens to be.
        return "'{$shown}'...(truncated, " . \strlen($raw) . ' bytes total)';
    }

    /**
     * @return array{0: string, 1: bool} the escaped preview, and whether
     *         it had to stop before consuming the whole raw string
     */
    private static function escapedBytePreview(string $raw): array
    {
        $shown = '';
        $rawLength = \strlen($raw);

        for ($offset = 0; $offset < $rawLength; $offset++) {
            $escapedByte = \addcslashes($raw[$offset], "\0..\37\177..\377");

            if (\strlen($shown) + \strlen($escapedByte) > self::MAX_PREVIEW_LENGTH) {
                return [$shown, true];
            }

            $shown .= $escapedByte;
        }

        return [$shown, false];
    }

    /**
     * The wire body itself — a whole envelope, or one JSON-encoded
     * sub-field such as SqlQueue's own `args`/`metadata` columns — isn't
     * valid JSON syntax at all, so there is no decoded value to describe
     * a shape problem for; $raw is the raw text as read from storage.
     */
    public static function invalidJson(string $field, string $raw): self
    {
        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value (" . self::preview($raw) . ') is not valid JSON '
            . '— the durable storage this job was read from may be corrupted.',
        );
    }

    /**
     * A value parsed as JSON (or was already a decoded PHP value, for a
     * field extracted out of an envelope this class's own caller already
     * decoded) but isn't the shape $field actually needs — a missing or
     * wrong-typed `class`/`args`/`metadata` field, or a decoded envelope
     * that isn't an object/array at all (a bare JSON string, number,
     * bool, or null where {class, args, ...} was expected).
     */
    public static function invalidShape(string $field, mixed $raw): self
    {
        $type = get_debug_type($raw);

        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value ({$type}, " . self::preview($raw) . ') has an '
            . 'unexpected shape — the durable storage this job was read from may be corrupted.',
        );
    }

    /**
     * A stored completed-attempts count that is exactly PHP_INT_MAX — a
     * genuinely valid, in-range integer on its own, but not one the
     * backend that found it can safely add one to (see
     * QueueContract::coerceStoredCompletedAttempts()'s own docblock for
     * why that increment would otherwise silently overflow to a float).
     */
    public static function notIncrementable(string $field): self
    {
        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value is PHP_INT_MAX, the largest "
            . 'representable integer — incrementing it for a retry would silently overflow, and a '
            . 'completed attempts count that has genuinely reached this value is implausible regardless, '
            . 'so this is treated as corrupted storage.',
        );
    }

    /**
     * $field's own key is missing entirely from the decoded envelope/row
     * — a distinct problem from a present value that's merely the wrong
     * shape (invalidShape()) or absent-and-legitimately-so (a value whose
     * own real absence is a normal, expected state): this is only ever
     * thrown for a field a backend's own write path always includes, so
     * its outright absence is itself the corruption — see
     * QueueContract::assertFieldPresent()'s own docblock for exactly
     * which fields that applies to and why.
     */
    public static function missingField(string $field): self
    {
        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" field is missing entirely — the durable "
            . 'storage this job was read from may be corrupted.',
        );
    }

    /**
     * $raw is a syntactically clean, in-range integer — coerceStoredInteger()
     * itself has nothing to reject — but fails a domain-specific bound
     * $requirement states in plain language (QueuedJob's own 1-indexed
     * attempts floor, or its non-negative-or-null maxAttempts contract).
     * Distinct from invalidShape(), which is about the value's own type,
     * not a well-typed value's numeric range.
     */
    public static function outOfBounds(string $field, mixed $raw, string $requirement): self
    {
        return new self(
            "Cannot decode a queued job: its stored \"{$field}\" value (" . self::preview($raw) . ") is out of bounds — {$requirement} "
            . '— the durable storage this job was read from may be corrupted.',
        );
    }
}
