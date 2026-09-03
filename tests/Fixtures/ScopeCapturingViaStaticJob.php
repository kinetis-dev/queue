<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Queue\Job;

/**
 * Records the RequestScope it ran in onto CapturedScopeHolder — see that
 * class's own docblock for why a static handoff is needed here instead
 * of an instance property, unlike ScopeCapturingJob.
 */
final class ScopeCapturingViaStaticJob implements Job
{
    public function handle(RequestScope $scope): void
    {
        CapturedScopeHolder::$scope = $scope;
    }
}
