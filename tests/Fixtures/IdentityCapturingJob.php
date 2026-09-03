<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * Records its own object identity (spl_object_id) onto a static holder
 * when handle() actually runs — proving, from outside, whether the
 * instance that ran was the same one a test pushed or a distinct,
 * reconstructed one. A live object has no property survivable through
 * JobSerializer at all (an object identity isn't portable data), so this
 * has to be a static handoff, the same reasoning CapturedScopeHolder's
 * own docblock already gives for RequestScope.
 */
final class IdentityCapturingJob implements Job
{
    public static ?int $ranWithId = null;

    public function handle(): void
    {
        self::$ranWithId = spl_object_id($this);
    }
}
