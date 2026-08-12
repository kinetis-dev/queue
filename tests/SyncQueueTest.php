<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\SyncQueue;
use Kinetis\Queue\Tests\Fixtures\FailingJob;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\ScopeCapturingJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncQueueTest extends TestCase
{
    private function app(): AppScope
    {
        $app = new AppScope();
        $app->instance(Recorder::class, new Recorder());
        $app->boot();

        return $app;
    }

    public function test_push_invokes_the_job_immediately(): void
    {
        $app = $this->app();
        $queue = new SyncQueue($app);

        $queue->push(new RecordingJob('ran synchronously'));

        self::assertSame(['ran synchronously'], $app->get(Recorder::class)->messages);
    }

    public function test_pop_always_returns_null(): void
    {
        $queue = new SyncQueue($this->app());

        self::assertNull($queue->pop());
        self::assertNull($queue->pop(5));
    }

    public function test_ack_and_release_are_no_ops(): void
    {
        $queue = new SyncQueue($this->app());
        $queuedJob = new QueuedJob(RecordingJob::class, ['message' => 'x'], handle: 1, queue: 'default');

        $queue->ack($queuedJob);
        $queue->release($queuedJob);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_failing_jobs_exception_propagates_to_the_caller(): void
    {
        $queue = new SyncQueue($this->app());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deliberate failure');

        $queue->push(new FailingJob('deliberate failure'));
    }

    public function test_each_push_gets_its_own_fresh_scope(): void
    {
        $queue = new SyncQueue($this->app());

        $first = new ScopeCapturingJob();
        $second = new ScopeCapturingJob();

        $queue->push($first);
        $queue->push($second);

        self::assertNotNull($first->capturedScope);
        self::assertNotNull($second->capturedScope);
        self::assertNotSame($first->capturedScope, $second->capturedScope);
    }
}
