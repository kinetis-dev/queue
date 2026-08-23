<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

final readonly class TestListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    public function onTestEvent(TestEvent $event): void
    {
        $this->recorder->record($event->message);
    }
}
