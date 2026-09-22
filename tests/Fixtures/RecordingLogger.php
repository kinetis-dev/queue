<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * $recorder, when given, also receives a "log:<level>" entry per call,
 * so a test can read a log line's position relative to the settlement
 * and renewal entries the queue fixture records into the same list —
 * the only way to see that a renewal failure is reported *after* the
 * job's own outcome rather than in place of it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function __construct(private readonly ?Recorder $recorder = null) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];

        $this->recorder?->record('log:' . (\is_scalar($level) ? (string) $level : 'unknown'));
    }
}
