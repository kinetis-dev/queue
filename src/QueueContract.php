<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use JsonException;
use Kinetis\Queue\Exception\InvalidAttemptsException;
use Kinetis\Queue\Exception\InvalidDelaySecondsException;
use Kinetis\Queue\Exception\InvalidMaxAttemptsException;
use Kinetis\Queue\Exception\InvalidPopTimeoutException;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\MalformedQueuedJobDataException;

/**
 * The one place every backend validates a queue name, a pop() timeout, or
 * push()'s own $delaySeconds/$maxAttempts arguments — a shared, tested
 * validator rather than each of RedisQueue/SqlQueue/SqsQueue/
 * RabbitMqQueue/SyncQueue rolling its own check (or, as before this
 * existed, none at all — $delaySeconds/$maxAttempts had no shared check
 * of any kind until assertValidPushArguments() below, so a negative value
 * meant something different, and often worse, on every backend: silently
 * treated as immediate, stored as an already-available timestamp, or sent
 * straight to a remote API to fail there instead). Every assertion method
 * here is pure: throws or returns nothing, never touches a backend, so it
 * costs nothing to call before any real I/O. coerceStoredInteger() is the
 * one exception to "assertion only" — it also returns a value, since a
 * durable backend's own pop() decoder needs the safely-parsed int back,
 * not just a yes/no on whether the raw wire value was one.
 *
 * $queues === [] is deliberately not rejected by assertValidQueueList()
 * — every backend already treats "nothing to check" as a legitimate,
 * degenerate case (pop() returns null immediately), not malformed input;
 * see QueueInterface's own docblock for why that's the one case left
 * alone.
 *
 * A queue name is more than "any non-empty string" — this is advertised
 * as one backend-agnostic name, but the four real backends do not agree
 * on what a name may contain: Amazon SQS's own standard-queue naming
 * rule is the narrowest of the four (up to 80 characters, alphanumeric
 * plus hyphen/underscore only — no periods at all, since a literal `.`
 * only ever appears as part of the reserved `.fifo` suffix FIFO queues
 * use, which SqsQueue does not support), while Redis, SQL, and AMQP
 * (RabbitMQ) each accept a much wider range of bytes. VALID_NAME_PATTERN
 * is that narrowest shape, adopted as the one shared "logical queue
 * name" grammar every backend is validated against — a portable name is
 * one that would be valid on all four, not merely on whichever backend
 * happens to be configured today. Control characters, whitespace,
 * path-like separators (`/`), and colons are all rejected by the same
 * rule as periods: none of them appear in any real, cross-backend-safe
 * queue name either.
 */
final class QueueContract
{
    /**
     * SQS's own real cap for a standard queue name.
     */
    private const int MAX_NAME_LENGTH = 80;

    private const string VALID_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,80}$/';

    private function __construct() {}

    /**
     * @phpstan-assert non-empty-string $queue
     */
    public static function assertValidQueueName(string $queue): void
    {
        if ($queue === '') {
            throw InvalidQueueNameException::empty();
        }

        if (preg_match(self::VALID_NAME_PATTERN, $queue) !== 1) {
            throw InvalidQueueNameException::malformed($queue, self::MAX_NAME_LENGTH);
        }
    }

    /**
     * @param list<string> $queues
     */
    public static function assertValidQueueList(array $queues): void
    {
        $seen = [];

        foreach ($queues as $queue) {
            self::assertValidQueueName($queue);

            if (isset($seen[$queue])) {
                throw InvalidQueueNameException::duplicate($queue);
            }

            $seen[$queue] = true;
        }
    }

    public static function assertValidPopTimeout(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 0) {
            throw InvalidPopTimeoutException::negative($timeoutSeconds);
        }
    }

    /**
     * The one entry point every backend's pop() starts with, before any
     * I/O — and, for the backends built on Support\PopSweep, the one
     * PopSweep::run() itself repeats internally too (see that class's
     * own docblock for why calling it directly must not depend on a
     * caller having already validated), so the two checks above are
     * never split across call sites inconsistently.
     *
     * @param list<string> $queues
     */
    public static function assertValidPopArguments(int $timeoutSeconds, array $queues): void
    {
        self::assertValidPopTimeout($timeoutSeconds);
        self::assertValidQueueList($queues);
    }

    public static function assertValidDelaySeconds(int $delaySeconds): void
    {
        if ($delaySeconds < 0) {
            throw InvalidDelaySecondsException::negative($delaySeconds);
        }
    }

    /**
     * Shared by push()'s own $maxAttempts argument and QueuedJob's
     * $maxAttempts property — the exact same constraint applies to both,
     * so there is only one place it's expressed.
     */
    public static function assertValidMaxAttempts(?int $maxAttempts): void
    {
        if ($maxAttempts !== null && $maxAttempts < 0) {
            throw InvalidMaxAttemptsException::negative($maxAttempts);
        }
    }

    public static function assertValidAttempts(int $attempts): void
    {
        if ($attempts < 1) {
            throw InvalidAttemptsException::belowOne($attempts);
        }
    }

    /**
     * The one entry point every backend's push() starts with, before
     * telemetry, serialization, scope creation, or any backend I/O — the
     * push()-side counterpart to assertValidPopArguments() above. A
     * backend with its own additional, narrower constraint (SqsQueue's
     * 900-second delay cap, SQS's own real limit) checks this first and
     * layers its own check on top, never instead of this one.
     */
    public static function assertValidPushArguments(int $delaySeconds, string $queue, ?int $maxAttempts): void
    {
        self::assertValidDelaySeconds($delaySeconds);
        self::assertValidQueueName($queue);
        self::assertValidMaxAttempts($maxAttempts);
    }

    /**
     * Every durable backend's pop() reads $attempts/$maxAttempts back off
     * its own wire format (a JSON payload, a database column, an SQS
     * message attribute, an AMQP header) — this is the one place any of
     * them turns that raw, untyped value into a real int, rather than each
     * reaching for a lossy `(int)` cast of its own. A non-numeric string
     * cast that way silently becomes 0, a value QueueWorker's own
     * exhaustion check would then treat as perfectly legitimate instead of
     * the sign of corrupted storage it actually is — this throws instead,
     * naming $field and the real value found. Leading/trailing whitespace,
     * a leading `+`, a decimal point, or scientific notation are all
     * rejected too, not merely tolerated and truncated: none of them are
     * shapes any backend here ever actually writes, so accepting them
     * would only widen what "valid" means for no real caller's benefit.
     * A genuine PHP int (RabbitMQ's own typed AMQP field tables, a JSON
     * number RedisQueue's json_decode() already parsed correctly) passes
     * straight through with no string handling at all.
     *
     * The regex alone only proves decimal *syntax* — it accepts a string
     * of any length, and `(int)` casting a syntactically valid decimal
     * whose magnitude exceeds this platform's PHP_INT_MAX/PHP_INT_MIN
     * silently clamps to that boundary rather than failing, the exact
     * lossy behavior this method exists to close. filter_var() with
     * FILTER_VALIDATE_INT is what actually closes it: unlike `(int)`, it
     * returns false for a numeric string outside the representable int
     * range rather than clamping, so it's used here specifically as the
     * range check, not the syntax check — it tolerates surrounding
     * whitespace and a leading `+` on its own, both of which the regex
     * gate has already rejected by the time a value reaches it, so
     * neither leaks back in. A leading-zero string (e.g. "007") is
     * rejected too, a real difference from the regex alone: no backend
     * here ever writes one, so requiring the canonical decimal form
     * costs nothing and keeps this method a stricter, not looser, filter
     * than a single check alone would be.
     *
     * Length is checked before the regex ever runs, not after: the
     * longest decimal representation this method can ever accept is the
     * platform's own most negative boundary, sign included
     * (`strlen((string) PHP_INT_MIN)` — 20 on the 64-bit builds this
     * project targets, computed from the real constant rather than
     * hardcoded so a different platform's own boundary is still handled
     * correctly). Anything longer is rejected outright before the regex
     * is asked to scan it at all — a corrupted payload or a non-Kinetis
     * publisher could otherwise turn one bookkeeping field into
     * unbounded validation work merely by making the stored string long,
     * regardless of what digits it actually contains.
     *
     * "-0" is rejected explicitly, not merely inherited from whatever
     * filter_var() happens to do with it (it accepts "-0" as plain 0,
     * confirmed directly rather than assumed) — no backend here ever
     * writes a signed zero, a plain "0" always representing one, so a
     * stored "-0" is itself already a sign of hand-edited or otherwise
     * non-canonical data; treating it as the same corruption a
     * non-numeric string already is keeps the accepted grammar the one
     * canonical shape every real caller actually produces, not a wider
     * one that merely happens to parse.
     *
     * The result is only ever a syntactically valid, range-representable
     * integer — QueuedJob's own constructor is still what enforces the
     * real attempts/maxAttempts bounds (1-or-greater, null-or-non-negative)
     * once the two are combined into an actual instance, regardless of
     * which backend produced them. It is deliberately not what guards
     * against PHP_INT_MAX itself overflowing into a float the moment a
     * completed-attempts backend adds one to it — that's a distinct
     * concern this method alone cannot see, since it has no idea whether
     * its caller is about to increment the result; see RedisQueue's/
     * SqlQueue's/RabbitMqQueue's own decode methods for that guard.
     */
    public static function coerceStoredInteger(mixed $raw, string $field): int
    {
        if (\is_int($raw)) {
            return $raw;
        }

        if (
            \is_string($raw)
            && \strlen($raw) <= \strlen((string) \PHP_INT_MIN)
            && $raw !== '-0'
            && preg_match('/^-?\d+$/', $raw) === 1
        ) {
            $parsed = filter_var($raw, FILTER_VALIDATE_INT);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw MalformedQueuedJobDataException::notAnInteger($field, $raw);
    }

    /**
     * The three "completed attempts" backends — RedisQueue, SqlQueue,
     * RabbitMqQueue — each add one to this value to produce
     * QueuedJob::$attempts, the 1-indexed attempt number pop() actually
     * returns (see that class's own docblock). SqsQueue is the one
     * exception: its ApproximateReceiveCount is already 1-indexed and
     * never incremented, so it calls coerceStoredAttempts() instead of
     * this method — see that method's own docblock for why the two check
     * different bounds. coerceStoredInteger() alone cannot guard against
     * either problem this method closes:
     *
     * - PHP_INT_MAX is a genuinely valid, in-range integer and passes
     *   that check cleanly on its own, but `PHP_INT_MAX + 1` silently
     *   overflows to a float rather than throwing, and that float then
     *   fails QueuedJob's own strictly-typed `int $attempts` constructor
     *   parameter with a confusing TypeError far from where the real
     *   corruption actually was. A completed attempts count that has
     *   genuinely reached PHP_INT_MAX is implausible regardless — over
     *   nine quintillion retries — so this rejects it outright.
     * - A negative stored count (a hand-edited or otherwise corrupted
     *   value) also passes coerceStoredInteger()'s own check cleanly —
     *   it's a perfectly valid integer — but produces a final attempts
     *   value below QueuedJob's own 1-indexed floor once incremented
     *   (-1 + 1 = 0), which would otherwise reach QueuedJob's constructor
     *   directly and throw Exception\InvalidAttemptsException there
     *   instead of the package-owned exception type
     *   settleIfMalformed() is designed to catch — an unexpected
     *   exception type from what is, in every real sense, exactly the
     *   same kind of corrupted storage a non-numeric or PHP_INT_MAX
     *   value already is.
     *
     * Both are rejected here, at the one point every "completed
     * attempts" backend's own decode path already routes through, rather
     * than left for QueuedJob's own constructor validation to catch
     * under a different exception type.
     */
    public static function coerceStoredCompletedAttempts(mixed $raw, string $field): int
    {
        $value = self::coerceStoredInteger($raw, $field);

        if ($value === PHP_INT_MAX) {
            throw MalformedQueuedJobDataException::notIncrementable($field);
        }

        if ($value < 0) {
            throw MalformedQueuedJobDataException::outOfBounds($field, $value, 'a completed-attempts count must not be negative');
        }

        return $value;
    }

    /**
     * SqsQueue's own ApproximateReceiveCount is already the final,
     * 1-indexed attempt number QueuedJob::$attempts needs directly — no
     * increment, unlike the three "completed attempts" backends
     * coerceStoredCompletedAttempts() serves — so the bound checked here
     * is QueuedJob's own floor itself (>= 1), not "must not be negative"
     * (>= 0): the two backends' own native counters start counting from
     * a different point, so an identical raw value would mean something
     * different validated against the wrong floor. A value that parses
     * as a clean integer but is 0 or negative would otherwise reach
     * QueuedJob's constructor directly and throw
     * Exception\InvalidAttemptsException there instead of the
     * package-owned exception type settleIfMalformed() is designed to
     * catch.
     */
    public static function coerceStoredAttempts(mixed $raw, string $field): int
    {
        $value = self::coerceStoredInteger($raw, $field);

        if ($value < 1) {
            throw MalformedQueuedJobDataException::outOfBounds($field, $value, 'must be 1 or greater');
        }

        return $value;
    }

    /**
     * $raw is null (no override was stored — every backend's own
     * QueuedJob::$maxAttempts defers to the processing worker's own
     * default in this case, the ordinary case) or whatever a caller
     * already extracted for the stored maxAttempts value. A value that
     * parses as a clean integer but is negative would otherwise reach
     * QueuedJob's constructor directly and throw
     * Exception\InvalidMaxAttemptsException there instead of the
     * package-owned exception type settleIfMalformed() is designed to
     * catch — checked here, at the one point every backend's own decode
     * path already routes a stored maxAttempts value through, rather
     * than left for that constructor to catch under a different
     * exception type.
     */
    public static function coerceStoredMaxAttempts(mixed $raw, string $field): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = self::coerceStoredInteger($raw, $field);

        if ($value < 0) {
            throw MalformedQueuedJobDataException::outOfBounds($field, $value, 'must not be negative');
        }

        return $value;
    }

    /**
     * $envelope[$field] must exist as a key at all, not merely resolve
     * to a usable value via `??` — the two are indistinguishable for a
     * field whose own legitimate value can itself be null (RedisQueue's
     * own maxAttempts, SqlQueue's own max_attempts column: both mean "no
     * override", a real, common case), so only checking presence
     * separately can tell "the key is present with an explicit null
     * value" (legitimate) apart from "the key is missing entirely" (a
     * truncated or otherwise corrupted envelope/row). Used only where a
     * backend's own write path is known to always include the field —
     * RedisQueue's own encode() always writes `maxAttempts`, and
     * SqlQueue's own fixed table schema always selects `max_attempts` —
     * never for a field a backend only conditionally writes (SqsQueue's/
     * RabbitMqQueue's own maxAttempts attribute/header, set only when a
     * caller's own push() actually passed one), where absence is already
     * the normal, legitimate state.
     */
    /**
     * @param array<string, mixed> $envelope
     */
    public static function assertFieldPresent(array $envelope, string $field): void
    {
        if (!\array_key_exists($field, $envelope)) {
            throw MalformedQueuedJobDataException::missingField($field);
        }
    }

    /**
     * $queueNamePrefix (SqsQueue, RabbitMqQueue) is validated once, at
     * construction, against the same grammar a queue name itself is —
     * concatenating two strings that each already satisfy
     * VALID_NAME_PATTERN can only ever produce another string built from
     * the same safe character set, so a backend combining a validated
     * prefix with a validated name never needs a third, separate
     * character check on the result. An empty prefix (the default — "no
     * prefix") is always valid, unlike an empty queue name.
     */
    public static function assertValidQueueNamePrefix(string $prefix): void
    {
        if ($prefix === '') {
            return;
        }

        if (preg_match(self::VALID_NAME_PATTERN, $prefix) !== 1) {
            throw InvalidQueueNameException::malformedPrefix($prefix, self::MAX_NAME_LENGTH);
        }
    }

    /**
     * Decodes raw wire text expected to be a JSON object/array — a
     * whole envelope (RedisQueue's own payload, SqsQueue's/RabbitMqQueue's
     * message body) or one JSON-encoded sub-field (SqlQueue's own
     * `args`/`metadata` columns) — into a plain PHP array, normalizing
     * both ways that can fail (invalid JSON syntax, or JSON that parses
     * but isn't an object/array at all — a bare string, number, bool, or
     * null) into MalformedQueuedJobDataException instead of letting a raw
     * JsonException or a downstream TypeError/notice escape from
     * whatever tries to read a field out of the result next.
     *
     * @return array<array-key, mixed>
     */
    public static function coerceStoredJsonArray(string $raw, string $field): array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedQueuedJobDataException::invalidJson($field, $raw);
        }

        if (!\is_array($decoded)) {
            throw MalformedQueuedJobDataException::invalidShape($field, $decoded);
        }

        return $decoded;
    }

    /**
     * $raw is whatever a caller already extracted for the job's `class`
     * — a decoded envelope's own `class` key, or (SqlQueue) a plain
     * database column value — so this only ever needs to check the
     * value's own shape, not decode anything itself.
     *
     * @return class-string<Job>
     */
    public static function coerceStoredClass(mixed $raw): string
    {
        if (!\is_string($raw) || $raw === '') {
            throw MalformedQueuedJobDataException::invalidShape('class', $raw);
        }

        /** @var class-string<Job> */
        return $raw;
    }

    /**
     * $raw is whatever a caller already extracted for the job's `args` —
     * a decoded envelope's own `args` key (RedisQueue, SqsQueue,
     * RabbitMqQueue, all of which decode one whole {class, args, ...}
     * body together) or an already JSON-decoded value (SqlQueue's own
     * `args` column, which carries only the args array with no
     * surrounding envelope, decoded via coerceStoredJsonArray() first).
     *
     * Every key must be a string, not merely is_array($raw) — a JSON
     * *list* (`"args": [value]`, no object keys at all) decodes to a
     * plain PHP array with integer keys, which is_array() alone accepts
     * cleanly. Every real JobSerializer::serialize() output uses
     * constructor parameter names as its own object keys, so an
     * integer-keyed (or mixed-keyed) args value is never something a
     * real push() wrote — and left unrejected here, it would reach
     * JobSerializer::reconstruct() instead, whose own
     * JobReconstructionException::unknownArgument() takes a `string`
     * parameter: passed an integer key under this codebase's
     * strict_types, that's a raw, incidental TypeError, thrown from
     * *inside* the job-execution try/catch in QueueWorker::processNext()
     * — not the decode step this class exists to guard — so the
     * malformed envelope would be treated as an ordinary failing job and
     * released/retried up to maxAttempts, wasting every one of those
     * attempts on an envelope that can never succeed, rather than
     * settled once, immediately, the way every other malformed-shape
     * field already is. An empty array remains valid — a genuine
     * zero-argument job's own args value — since it has no keys, string
     * or otherwise, to reject.
     *
     * @return array<string, mixed>
     */
    public static function coerceStoredArgs(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw MalformedQueuedJobDataException::invalidShape('args', $raw);
        }

        foreach (array_keys($raw) as $key) {
            if (!\is_string($key)) {
                throw MalformedQueuedJobDataException::invalidShape('args', $raw);
            }
        }

        /** @var array<string, mixed> */
        return $raw;
    }

    /**
     * Every backend's own push() writes metadata (Telemetry's own
     * propagation channel — see QueuedJob::$metadata) as a flat map of
     * string keys to string values, wherever it stores it: inline inside
     * a bigger decoded envelope (RedisQueue, already an array by the
     * time it reaches here), or as its own separate JSON-encoded
     * attribute/header/column (SqlQueue, SqsQueue, RabbitMqQueue — still
     * a raw string at this point). $raw accepts either shape — null (no
     * metadata was stored at all), an already-decoded array, or a raw
     * JSON string — so every backend can hand this whatever it actually
     * has on hand rather than each decoding its own metadata source
     * first. Anything that isn't ultimately a string-to-string map, once
     * resolved, is corrupted data — not a value QueuedJob::$metadata's
     * own consumers (Telemetry's trace-propagation code, in particular)
     * should ever have to guard against seeing.
     *
     * @return array<string, string>
     */
    public static function coerceStoredMetadata(mixed $raw, string $field = 'metadata'): array
    {
        if ($raw === null) {
            return [];
        }

        if (\is_string($raw)) {
            $raw = self::coerceStoredJsonArray($raw, $field);
        }

        if (!\is_array($raw)) {
            throw MalformedQueuedJobDataException::invalidShape($field, $raw);
        }

        foreach ($raw as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                throw MalformedQueuedJobDataException::invalidShape($field, $raw);
            }
        }

        /** @var array<string, string> */
        return $raw;
    }

    /**
     * The one place every durable backend's pop() routes its own
     * decode-a-reserved-message-into-a-QueuedJob step through, so a
     * genuinely malformed message never crashes the worker loop or gets
     * left stranded/replayed forever (see MalformedJobSettledException's
     * own docblock for why a stranded/replaying poison message is the
     * concrete failure mode this closes).
     *
     * $decode is caught narrowly — MalformedQueuedJobDataException only,
     * never a blanket Throwable — and this is load-bearing, not
     * incidental: settling a message means permanently deleting it, and
     * that's only ever the correct response to the *data itself* being
     * unusable. Every expected data-validation failure a decode path can
     * hit is made to throw this one package-owned type before
     * QueuedJob's constructor is ever reached — every coercion helper
     * above (coerceStoredJsonArray()/coerceStoredClass()/
     * coerceStoredArgs()/coerceStoredMetadata()/coerceStoredInteger()/
     * coerceStoredCompletedAttempts()/coerceStoredAttempts()/
     * coerceStoredMaxAttempts()/assertFieldPresent()) throws it, and
     * covers every bound QueuedJob's own constructor would otherwise
     * enforce a second time under a different exception type. Anything
     * else $decode throws — a TypeError from an undefined-method call, an
     * AssertionError, any other programming defect in a decoder — is a
     * bug in Kinetis's own code, not evidence the stored message is
     * malformed, and deleting a possibly-perfectly-valid message over a
     * framework bug would be real, silent data loss dressed up as a
     * safety mechanism. Left uncaught here, it propagates all the way out
     * of pop() (QueueWorker has no catch for it either — only for
     * MalformedJobSettledException specifically), which is deliberately
     * the same "crash and leave the backend's own native reservation
     * recovery to handle it" outcome this whole mechanism existed to
     * avoid *only* for genuine data corruption — for a real code defect,
     * that outcome is the correct, conservative one: the message stays
     * recoverable once the bug is fixed, rather than being destroyed
     * because of it.
     *
     * $settle is the backend's own fail()-equivalent primitive for the
     * message $decode was just given — called only once $decode has
     * thrown the package-owned exception, and never itself caught: a
     * real settlement failure (the call that's supposed to remove/ack
     * the poison message failing in turn) must propagate exactly like
     * any other transport failure would, not be silently absorbed behind
     * the more interesting-looking malformed-data outcome it was trying
     * to report. Never called at all for anything reserved *before* this
     * method runs a $decode that never throws — the ordinary, successful
     * case, which this method returns unchanged — nor for a $decode
     * failure of any other type, per the above.
     *
     * @template T
     * @param callable(): T $decode
     * @param callable(): void $settle
     * @return T
     */
    public static function settleIfMalformed(string $queue, callable $decode, callable $settle): mixed
    {
        try {
            return $decode();
        } catch (MalformedQueuedJobDataException $e) {
            $settle();

            throw new MalformedJobSettledException($queue, $e);
        }
    }
}
