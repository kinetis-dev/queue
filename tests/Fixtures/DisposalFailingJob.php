<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Queue\Job;
use RuntimeException;

/**
 * Succeeds, but registers two onDispose callbacks on its own scope — the
 * first always throws, the second records that it ran — proving
 * QueueWorker's own disposal containment (see QueueWorker::
 * disposeScope()) composes with RequestScope::dispose()'s existing
 * "every callback runs, even after an earlier one throws" guarantee, not
 * just that the first callback's failure alone is contained.
 */
final class DisposalFailingJob implements Job
{
    public function handle(RequestScope $scope): void
    {
        CapturedScopeHolder::$scope = $scope;

        $scope->onDispose(static function (): void {
            throw new RuntimeException('dispose callback failed');
        });
        $scope->onDispose(static function (): void {
            DisposalCallbackHolder::$secondRan = true;
        });
    }
}
