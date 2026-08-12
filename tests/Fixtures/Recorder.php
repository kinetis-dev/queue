<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $messages = [];

    public function record(string $message): void
    {
        $this->messages[] = $message;
    }
}
