<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Queue\Exception\InvalidAttemptsException;
use Kinetis\Queue\Exception\InvalidMaxAttemptsException;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * QueuedJob's own constructor is the one point every ack()/release()/
 * fail() call's $job->queue ultimately passed through — a real
 * QueuedJob only ever comes from a backend's own pop() (which already
 * validates before ever reaching here), or from a caller constructing
 * one directly (a test, a hand-rolled QueueInterface). Validating here
 * closes both: once an instance exists, $job->queue is guaranteed a
 * real, well-formed name, so nothing downstream needs its own redundant
 * check before touching backend storage on ack()/release()/fail(). The
 * identical reasoning covers $attempts/$maxAttempts: this is the one
 * point malformed backend data or a hand-built job either one alike
 * passes through before QueueWorker could ever misclassify a job's very
 * first real attempt as already exhausted.
 */
final class QueuedJobTest extends TestCase
{
    public function test_a_valid_queue_name_is_accepted(): void
    {
        $job = new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default');

        self::assertSame('default', $job->queue);
    }

    public function test_an_empty_queue_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);

        new QueuedJob('Fixture\\Job', [], handle: 'h', queue: '');
    }

    public function test_a_malformed_queue_name_is_rejected(): void
    {
        $this->expectException(InvalidQueueNameException::class);

        new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'has spaces');
    }

    public function test_an_attempts_value_of_one_is_accepted(): void
    {
        $job = new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', attempts: 1);

        self::assertSame(1, $job->attempts);
    }

    public function test_an_attempts_value_above_one_is_accepted(): void
    {
        $job = new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', attempts: 3);

        self::assertSame(3, $job->attempts);
    }

    public function test_an_attempts_value_of_zero_is_rejected(): void
    {
        $this->expectException(InvalidAttemptsException::class);

        new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', attempts: 0);
    }

    public function test_a_negative_attempts_value_is_rejected(): void
    {
        $this->expectException(InvalidAttemptsException::class);

        new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', attempts: -1);
    }

    public function test_a_null_max_attempts_is_accepted(): void
    {
        $job = new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', maxAttempts: null);

        self::assertNull($job->maxAttempts);
    }

    public function test_a_zero_max_attempts_is_accepted(): void
    {
        $job = new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', maxAttempts: 0);

        self::assertSame(0, $job->maxAttempts);
    }

    public function test_a_negative_max_attempts_is_rejected(): void
    {
        $this->expectException(InvalidMaxAttemptsException::class);

        new QueuedJob('Fixture\\Job', [], handle: 'h', queue: 'default', maxAttempts: -1);
    }
}
