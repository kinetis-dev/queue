<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;

final readonly class RecordingQueueEventListener
{
    public function __construct(
        private QueueEventLog $log,
    ) {}

    #[Listener]
    public function onJobSucceeded(JobSucceeded $event): void
    {
        $this->log->succeeded[] = $event;
    }

    #[Listener]
    public function onJobReleased(JobReleased $event): void
    {
        $this->log->released[] = $event;
    }

    #[Listener]
    public function onJobFailedPermanently(JobFailedPermanently $event): void
    {
        $this->log->failedPermanently[] = $event;
    }
}
