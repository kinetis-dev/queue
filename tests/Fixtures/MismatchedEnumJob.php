<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A parameter declaring one enum whose same-named property holds a case
 * of another. Contrived on purpose: it is the only way to present
 * JobSerializer with a declared type that names a real enum class and a
 * value that is not one of its cases, which is what pins the check to
 * the value's own class rather than to "some enum was declared here".
 */
final class MismatchedEnumJob implements Job
{
    public mixed $mode;

    public function __construct(Priority $mode)
    {
        $this->mode = $mode === Priority::High ? Severity::Critical : Severity::Info;
    }

    public function handle(): void {}
}
