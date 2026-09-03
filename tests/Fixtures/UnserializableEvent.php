<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

/**
 * A deliberately untyped constructor argument — the event-side
 * counterpart to PayloadJob, for proving QueuedListenerInvoker's own
 * JobSerializer::serialize($event) call rejects an unsupported value the
 * identical way a job's own constructor argument would.
 */
final readonly class UnserializableEvent
{
    public function __construct(
        public mixed $payload,
    ) {}
}
