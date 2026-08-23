<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * $name is computed into $greeting in the constructor body, not stored
 * as a same-named property — JobSerializer has nothing to read back for
 * it.
 */
final class UnserializableJob implements Job
{
    private string $greeting;

    public function __construct(string $name)
    {
        $this->greeting = "Hello, {$name}!";
    }

    public function handle(): void
    {
    }
}
