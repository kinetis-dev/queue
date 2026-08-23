<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;

final class QueueEventLog
{
    /** @var list<JobSucceeded> */
    public array $succeeded = [];

    /** @var list<JobReleased> */
    public array $released = [];

    /** @var list<JobFailedPermanently> */
    public array $failedPermanently = [];
}
