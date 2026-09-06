<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * A constructor argument with no declared type at all — the one shape
 * that leaves reconstruction with nothing to read, so JobSerializer
 * rejects an enum case or a date held here.
 */
final class UntypedArgumentJob implements Job
{
    public mixed $mode;

    /**
     * @param mixed $mode the undeclared type is what this fixture is for
     */
    public function __construct($mode)
    {
        $this->mode = $mode;
    }

    public function handle(): void {}
}
