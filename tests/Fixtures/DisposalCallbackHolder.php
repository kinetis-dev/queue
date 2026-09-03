<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

/**
 * A static handoff so a test can confirm a second onDispose callback
 * still ran despite an earlier one throwing — the same static-handoff
 * shape CapturedScopeHolder already establishes, needed for the same
 * reason: a job popped through a real QueueInterface is a freshly
 * deserialized instance, not the one a test held a reference to.
 */
final class DisposalCallbackHolder
{
    public static bool $secondRan = false;
}
