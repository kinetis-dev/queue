<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use DateTimeImmutable;

/**
 * One constructor argument per supported rich/nested wire shape — a
 * nested list-of-maps, a BackedEnum case, and a DateTimeImmutable — so
 * QueuedListenerInvoker/InvokeListenerJob's own round trip can be proven
 * against the same conformance matrix JobSerializer's own tests already
 * cover, not just a single flat string.
 */
final readonly class RichEvent
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public Priority $priority,
        public DateTimeImmutable $occurredAt,
    ) {}
}
