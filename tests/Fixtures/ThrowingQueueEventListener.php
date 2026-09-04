<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSettlementLost;
use Kinetis\Queue\Events\JobSucceeded;
use RuntimeException;

/**
 * Every lifecycle-event listener throws — proving a broken observer can
 * never affect the outcome that was already settled, or already lost,
 * before it ran, and can never crash the worker.
 */
final class ThrowingQueueEventListener
{
    #[Listener]
    public function onJobSucceeded(JobSucceeded $event): void
    {
        throw new RuntimeException('JobSucceeded listener failed.');
    }

    #[Listener]
    public function onJobReleased(JobReleased $event): void
    {
        throw new RuntimeException('JobReleased listener failed.');
    }

    #[Listener]
    public function onJobFailedPermanently(JobFailedPermanently $event): void
    {
        throw new RuntimeException('JobFailedPermanently listener failed.');
    }

    #[Listener]
    public function onJobSettlementLost(JobSettlementLost $event): void
    {
        throw new RuntimeException('JobSettlementLost listener failed.');
    }
}
