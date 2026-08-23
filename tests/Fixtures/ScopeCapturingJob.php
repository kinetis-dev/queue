<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Queue\Job;

final class ScopeCapturingJob implements Job
{
    public ?RequestScope $capturedScope = null;

    public function handle(RequestScope $scope): void
    {
        $this->capturedScope = $scope;
    }
}
