<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

final readonly class RichEventListener
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    public function onRichEvent(RichEvent $event): void
    {
        $this->recorder->record(sprintf(
            '%s|%s|%s',
            json_encode($event->items, JSON_THROW_ON_ERROR),
            $event->priority->value,
            $event->occurredAt->format('Y-m-d\TH:i:s.uP'),
        ));
    }
}
