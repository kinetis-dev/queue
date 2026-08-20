<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

/**
 * release() found no matching entry to move — the job was already
 * released, acked, or failed through another call: a duplicate
 * release() with the same QueuedJob, a stale handle, or a retry after a
 * client-side failure whose server-side outcome had actually already
 * landed (RedisQueue's release() is a single atomic Lua script, so
 * "landed" and "acknowledged to the client" can only differ when the
 * connection itself drops between the two). No new pending entry was
 * written in any of these cases — the state transition this handle
 * represents has already happened, exactly once.
 */
final class StaleJobHandleException extends RuntimeException
{
    public static function forRelease(string $queue): self
    {
        return new self(
            "release() found no matching entry in the \"{$queue}\" queue's processing list — the job was "
            . 'already released, acked, or failed through another call. No new pending entry was written.',
        );
    }
}
