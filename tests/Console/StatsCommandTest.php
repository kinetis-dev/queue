<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Console;

use Kinetis\Console\CommandArguments;
use Kinetis\Queue\Console\StatsCommand;
use Kinetis\Queue\Tests\Fixtures\InMemoryQueue;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use PHPUnit\Framework\TestCase;

final class StatsCommandTest extends TestCase
{
    public function test_stats_reports_the_waiting_count_for_the_default_queue(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'));
        $queue->push(new RecordingJob('b'));

        [$code, $output] = self::invoke(new StatsCommand($queue, self::stream()), []);

        self::assertSame(0, $code);
        self::assertStringContainsString('default', $output);
        self::assertStringContainsString('2', $output);
    }

    public function test_stats_totals_several_queues(): void
    {
        $queue = new InMemoryQueue();
        $queue->push(new RecordingJob('a'), queue: 'high');
        $queue->push(new RecordingJob('b'), queue: 'high');
        $queue->push(new RecordingJob('c'), queue: 'default');

        [$code, $output] = self::invoke(new StatsCommand($queue, self::stream()), ['--queue=high,default']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/high\s+2/', $output);
        self::assertMatchesRegularExpression('/default\s+1/', $output);
        self::assertMatchesRegularExpression('/total\s+3/', $output);
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
    private static function invoke(StatsCommand $command, array $argv): array
    {
        $output = (new \ReflectionProperty($command, 'output'))->getValue($command);
        self::assertIsResource($output);

        $code = $command->run(CommandArguments::parse($argv));

        rewind($output);

        return [$code, (string) stream_get_contents($output)];
    }
}
