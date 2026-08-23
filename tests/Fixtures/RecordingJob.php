<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

final readonly class RecordingJob implements Job
{
    public function __construct(
        public string $message,
    ) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->record($this->message);
    }
}
