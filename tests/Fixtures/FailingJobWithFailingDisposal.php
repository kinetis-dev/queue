<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Queue\Job;
use RuntimeException;

/**
 * The dual-failure case: the job itself fails, and its scope's own
 * disposal also fails. release()/fail() must already have happened
 * before this job's own exception reaches QueueWorker, so a disposal
 * failure on top of it must be contained the same way DisposalFailingJob
 * proves for the successful case — never a second transition, never
 * escaping processNext().
 */
final class FailingJobWithFailingDisposal implements Job
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

        throw new RuntimeException('the job itself failed');
    }
}
