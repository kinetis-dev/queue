<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use JsonException;
use Kinetis\Queue\Exception\InvalidQueueArgumentException;
use Kinetis\Queue\Exception\MalformedJobSettledException;
use Kinetis\Queue\Exception\MalformedQueuedJobDataException;

/**
 * The shared validation and decode helpers every backend runs, so
 * RedisQueue, SqlQueue, SqsQueue, RabbitMqQueue and SyncQueue agree on
 * what a queue name is, what push()/pop() accept, and what a corrupted
 * stored message looks like.
 *
 * The assertion methods are pure — they throw or return nothing, and
 * touch no backend — so a backend calls them before any I/O. The
 * stored* methods additionally return the parsed value, since a
 * backend's decoder needs it.
 *
 * An empty $queues list is not rejected: "nothing to check" is a
 * legitimate degenerate case, and pop() returns null immediately.
 */
final class QueueContract
{
    /** Amazon SQS's own cap for a standard queue name, the narrowest of the four backends. */
    private const int MAX_NAME_LENGTH = 80;

    private const string VALID_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,80}$/D';

    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * @phpstan-assert non-empty-string $queue
     */
    public static function assertValidQueueName(string $queue): void
    {
        if ($queue === '') {
            throw InvalidQueueArgumentException::emptyQueueName();
        }

        if (preg_match(self::VALID_NAME_PATTERN, $queue) !== 1) {
            throw InvalidQueueArgumentException::malformedQueueName($queue, self::MAX_NAME_LENGTH);
        }
    }

    /**
     * A prefix is validated against the same grammar a name is, so a
     * backend concatenating the two never needs a third check on the
     * result. An empty prefix means "no prefix" and is always valid.
     */
    public static function assertValidQueueNamePrefix(string $prefix): void
    {
        if ($prefix !== '' && preg_match(self::VALID_NAME_PATTERN, $prefix) !== 1) {
            throw InvalidQueueArgumentException::malformedQueueNamePrefix($prefix, self::MAX_NAME_LENGTH);
        }
    }

    /**
     * The one entry point every backend's pop() starts with, before any
     * I/O.
     *
     * @param list<string> $queues
     */
    public static function assertValidPopArguments(int $timeoutSeconds, array $queues): void
    {
        if ($timeoutSeconds < 0) {
            throw InvalidQueueArgumentException::negativePopTimeout($timeoutSeconds);
        }

        self::assertValidQueueList($queues);
    }

    /**
     * Validates a whole list before anything acts on any of it — the
     * shape pop() and queue:clear both need, since checking name by name
     * would let `--queue=default,not a name` clear `default` before
     * rejecting the rest.
     *
     * @param list<string> $queues
     */
    public static function assertValidQueueList(array $queues): void
    {
        $seen = [];

        foreach ($queues as $queue) {
            self::assertValidQueueName($queue);

            if (isset($seen[$queue])) {
                throw InvalidQueueArgumentException::duplicateQueueName($queue);
            }

            $seen[$queue] = true;
        }
    }

    /**
     * The push()-side counterpart, run before telemetry, serialization or
     * any backend I/O. A backend with a narrower rule of its own (SqsQueue's
     * 900-second delay cap) layers it on top of this, never instead of it.
     */
    public static function assertValidPushArguments(int $delaySeconds, string $queue, ?int $maxAttempts): void
    {
        if ($delaySeconds < 0) {
            throw InvalidQueueArgumentException::negativeDelaySeconds($delaySeconds);
        }

        self::assertValidQueueName($queue);
        self::assertValidMaxAttempts($maxAttempts);
    }

    public static function assertValidAttempts(int $attempts): void
    {
        if ($attempts < 1) {
            throw InvalidQueueArgumentException::attemptsBelowOne($attempts);
        }
    }

    public static function assertValidMaxAttempts(?int $maxAttempts): void
    {
        if ($maxAttempts !== null && $maxAttempts < 0) {
            throw InvalidQueueArgumentException::negativeMaxAttempts($maxAttempts);
        }
    }

    /**
     * $envelope[$field] must be present as a key, not merely resolve via
     * `??` — the two are indistinguishable for a field whose legitimate
     * value can itself be null (a stored maxAttempts meaning "no
     * override"). Used only where the backend's write path always emits
     * the field.
     *
     * @param array<string, mixed> $envelope
     */
    public static function assertFieldPresent(array $envelope, string $field): void
    {
        if (!\array_key_exists($field, $envelope)) {
            throw MalformedQueuedJobDataException::missingField($field);
        }
    }

    /**
     * Turns a raw stored counter into an int within [$min, $max].
     *
     * A backend reads these back off JSON, a database column, an SQS
     * message attribute or an AMQP header, so the raw value may be an int
     * already or a decimal string. A `(int)` cast is not enough: it turns
     * a non-numeric string into 0, which QueueWorker's exhaustion check
     * would then read as a legitimate count, and it clamps an
     * out-of-range decimal to PHP_INT_MAX/PHP_INT_MIN instead of failing.
     * filter_var() rejects both.
     *
     * $max is what lets the three backends storing a *completed* attempts
     * count pass PHP_INT_MAX - 1: they add one to the result, and
     * PHP_INT_MAX + 1 silently becomes a float.
     */
    public static function storedInt(mixed $raw, string $field, int $min, int $max = PHP_INT_MAX): int
    {
        $value = \is_int($raw)
            ? $raw
            : (\is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false);

        if (!\is_int($value)) {
            throw MalformedQueuedJobDataException::notAnInteger($field, $raw);
        }

        if ($value < $min || $value > $max) {
            throw MalformedQueuedJobDataException::outOfBounds($field, "must be between {$min} and {$max}");
        }

        return $value;
    }

    /**
     * null means no stored override — the ordinary case, where the
     * processing worker's own default applies.
     */
    public static function storedNullableInt(mixed $raw, string $field, int $min): ?int
    {
        return $raw === null ? null : self::storedInt($raw, $field, $min);
    }

    /**
     * Decodes raw wire text expected to be a JSON object or array — a
     * whole envelope, or one JSON-encoded column — normalizing both
     * failure modes (invalid syntax, or valid JSON that is a scalar)
     * into one exception instead of a raw JsonException or a later
     * TypeError.
     *
     * @return array<array-key, mixed>
     */
    public static function storedJsonArray(string $raw, string $field): array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedQueuedJobDataException::invalidJson($field);
        }

        if (!\is_array($decoded)) {
            throw MalformedQueuedJobDataException::invalidShape($field, $decoded);
        }

        return $decoded;
    }

    /**
     * @return class-string<Job>
     */
    public static function storedClass(mixed $raw): string
    {
        if (!\is_string($raw) || $raw === '') {
            throw MalformedQueuedJobDataException::invalidShape('class', $raw);
        }

        /** @var class-string<Job> */
        return $raw;
    }

    /**
     * Every key must be a string, not merely is_array() — a JSON list
     * decodes to an integer-keyed array, which no real push() ever wrote
     * (JobSerializer keys args by constructor parameter name). Left
     * unrejected it would reach reconstruct() as an ordinary job failure
     * and burn every retry on a payload that can never succeed. An empty
     * array stays valid: a zero-argument job.
     *
     * @return array<string, mixed>
     */
    public static function storedArgs(mixed $raw): array
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
     * $raw is null (nothing stored), an already-decoded array, or raw
     * JSON, so each backend passes whatever its own storage gives it.
     * The result is always a flat string-to-string map — what
     * QueuedJob::$metadata's consumers, trace propagation above all,
     * are entitled to assume.
     *
     * @return array<string, string>
     */
    public static function storedMetadata(mixed $raw, string $field = 'metadata'): array
    {
        if ($raw === null) {
            return [];
        }

        if (\is_string($raw)) {
            $raw = self::storedJsonArray($raw, $field);
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
     * The one place every durable backend routes its decode step through,
     * so a malformed message is settled permanently instead of crashing
     * the worker loop or replaying forever.
     *
     * $decode is caught narrowly, and that is load-bearing: settling
     * means deleting, which is only ever right when the *data* is
     * unusable. Every decode helper above raises
     * MalformedQueuedJobDataException for exactly that. Anything else —
     * a TypeError, an AssertionError — is a defect in Kinetis's own code,
     * and destroying a possibly-valid message over it would be data loss;
     * it propagates instead, leaving the message recoverable once the bug
     * is fixed.
     *
     * $settle is the backend's own fail()-equivalent for this message and
     * is never caught: a settlement that itself fails is a transport
     * failure and must surface as one.
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
