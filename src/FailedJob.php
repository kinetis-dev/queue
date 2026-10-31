<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * A job QueueWorker gave up on, as {@see FailedJobRecorderInterface}
 * persists it: everything a retry needs to push it again, plus what the
 * final attempt threw.
 *
 * $connection is the `--connection` value `queue:work` ran with, or null
 * when it ran the bound QueueInterface — the same two targets a retry
 * can push onto. $args is the raw JobSerializer form, #[Sensitive]
 * values included, because a retry has to reconstruct the job;
 * {@see Attributes\Sensitive} governs what is logged, not what is stored.
 * $maxAttempts is the cap the job was pushed with, null when it deferred
 * to the worker's default.
 *
 * $exceptionMessage is cut to MAX_EXCEPTION_MESSAGE_BYTES of valid UTF-8,
 * with invalid sequences substituted first, so an arbitrary exception
 * message can never make the record itself unwritable. No stack trace
 * and no trace metadata are kept.
 */
final readonly class FailedJob
{
    public const int MAX_EXCEPTION_MESSAGE_BYTES = 2048;

    public string $exceptionMessage;

    /**
     * @param class-string<Job> $class
     * @param array<string, mixed> $args
     * @param int $failedAt unix seconds
     */
    public function __construct(
        public ?string $connection,
        public string $queue,
        public string $class,
        public array $args,
        public ?int $maxAttempts,
        public int $attempts,
        public string $exceptionClass,
        string $exceptionMessage,
        public int $failedAt,
    ) {
        if ($connection !== null) {
            QueueContract::assertValidConnectionName($connection, 'the failed job\'s connection');
        }

        QueueContract::assertValidQueueName($queue);

        $this->exceptionMessage = self::bounded($exceptionMessage);
    }

    private static function bounded(string $message): string
    {
        $valid = json_decode(
            json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            flags: JSON_THROW_ON_ERROR,
        );
        \assert(\is_string($valid));

        if (\strlen($valid) <= self::MAX_EXCEPTION_MESSAGE_BYTES) {
            return $valid;
        }

        // A byte cut of valid UTF-8 can split at most one trailing
        // character, whose remaining one to three bytes are dropped here.
        $cut = substr($valid, 0, self::MAX_EXCEPTION_MESSAGE_BYTES);

        while (preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
