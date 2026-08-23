<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * Runs after each record(), so a test can act from inside a job —
     * stopping the worker mid-loop, for one.
     *
     * @var (callable(): void)|null
     */
    public $onRecord = null;

    public function record(string $message): void
    {
        $this->messages[] = $message;

        if ($this->onRecord !== null) {
            ($this->onRecord)();
        }
    }
}
