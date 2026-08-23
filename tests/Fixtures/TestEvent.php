<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

final readonly class TestEvent
{
    public function __construct(
        public string $message,
    ) {}
}
