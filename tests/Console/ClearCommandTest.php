<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Console;

use Kinetis\Console\CommandArguments;
use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Console\ClearCommand;
use Kinetis\Queue\Exception\InvalidQueueNameException;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\NeverCalledQueue;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The command holds a QueueInterface, so the capability check it makes
 * before touching anything is the behavior under test here — alongside
 * the --force gate, whole-list validation, and the count it reports
 * back.
 */
final class ClearCommandTest extends TestCase
{
    public function test_clear_refuses_without_force_and_leaves_the_queue_intact(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'));

        [$code, $output] = self::invoke(new ClearCommand($queue, self::stream()), []);

        self::assertSame(1, $code);
        self::assertStringContainsString('--force', $output);
        self::assertSame(1, $queue->size());
    }

    public function test_clear_with_force_reports_what_the_backend_removed(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'));
        $queue->push(new RecordingJob('b'));

        [$code, $output] = self::invoke(new ClearCommand($queue, self::stream()), ['--force']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Cleared 2 job(s)', $output);
        self::assertSame(0, $queue->size());
    }

    public function test_clear_names_every_queue_it_was_given(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'), queue: 'high');
        $queue->push(new RecordingJob('b'), queue: 'default');

        [$code, $output] = self::invoke(new ClearCommand($queue, self::stream()), ['--queue=high,default', '--force']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Cleared 1 job(s) from "high"', $output);
        self::assertStringContainsString('Cleared 1 job(s) from "default"', $output);
        self::assertSame(0, $queue->size('high'));
        self::assertSame(0, $queue->size('default'));
    }

    public function test_a_backend_without_the_capability_is_named_along_with_what_to_do_instead(): void
    {
        // --force is supplied, so the refusal below can only come from
        // the capability check; NeverCalledQueue throws on every backend
        // operation, which is what proves no queue was touched first.
        [$code, $output] = self::invoke(new ClearCommand(new NeverCalledQueue(), self::stream()), ['--force']);

        self::assertSame(1, $code);
        self::assertStringContainsString(NeverCalledQueue::class, $output);
        self::assertStringContainsString(ClearableQueueInterface::class, $output);
        self::assertStringContainsString('infrastructure tooling', $output);

        // Advice that can never work on this backend, so it must not
        // appear: adding --force changes nothing here.
        self::assertStringNotContainsString('--force', $output);
    }

    /**
     * The first name is valid and would clear a real queue; the second
     * is not a queue name at all. Nothing may be discarded before the
     * whole list has been accepted, so `high` still holds its job after
     * the rejection.
     */
    public function test_an_invalid_later_name_discards_nothing_from_the_valid_earlier_one(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'), queue: 'high');

        $command = new ClearCommand($queue, self::stream());

        try {
            $command->run(CommandArguments::parse(['--queue=high,not a name', '--force']));
            self::fail('Expected the malformed name to be rejected.');
        } catch (InvalidQueueNameException) {
            self::assertSame(1, $queue->size('high'));
        }
    }

    /**
     * A repeated name is rejected by the same whole-list check, and for
     * the same reason: the run never started, so the queue named twice
     * is untouched rather than cleared once on the way to the refusal.
     */
    public function test_a_duplicate_name_discards_nothing_from_the_queues_before_it(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'), queue: 'high');
        $queue->push(new RecordingJob('b'), queue: 'default');

        $command = new ClearCommand($queue, self::stream());

        try {
            $command->run(CommandArguments::parse(['--queue=high,default,high', '--force']));
            self::fail('Expected the duplicate name to be rejected.');
        } catch (InvalidQueueNameException) {
            self::assertSame(1, $queue->size('high'));
            self::assertSame(1, $queue->size('default'));
        }
    }

    /**
     * The list is validated ahead of the --force gate too, so a refusal
     * never reports back a set of queue names the command would not
     * have accepted in the first place.
     */
    public function test_an_invalid_name_is_rejected_even_without_force(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'), queue: 'high');

        $command = new ClearCommand($queue, self::stream());

        try {
            $command->run(CommandArguments::parse(['--queue=high,not a name']));
            self::fail('Expected the malformed name to be rejected.');
        } catch (InvalidQueueNameException) {
            self::assertSame(1, $queue->size('high'));
        }
    }

    public function test_a_clear_capable_backend_is_reachable_through_the_capability_type(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'));

        self::assertInstanceOf(ClearableQueueInterface::class, $queue);
        self::assertSame(1, self::clearThrough($queue));
        self::assertSame(0, $queue->size());
    }

    /**
     * Typed as the capability, not the concrete fixture: passing a
     * backend that stopped declaring ClearableQueueInterface would be a
     * TypeError here rather than a silent pass.
     */
    private static function clearThrough(ClearableQueueInterface $queue, string $name = 'default'): int
    {
        return $queue->clear($name);
    }

    /** @return resource */
    private static function stream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private static function invoke(ClearCommand $command, array $argv): array
    {
        $output = (new ReflectionProperty($command, 'output'))->getValue($command);
        self::assertIsResource($output);

        $code = $command->run(CommandArguments::parse($argv));

        rewind($output);

        return [$code, (string) stream_get_contents($output)];
    }
}
