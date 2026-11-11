<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Console;

use Kinetis\Console\CommandArguments;
use Kinetis\Queue\Console\StatsCommand;
use Kinetis\Queue\Tests\Fixtures\CommandOutput;
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

        [$code, $output] = self::invoke($queue, []);

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

        [$code, $output] = self::invoke($queue, ['--queue=high,default']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/high\s+2/', $output);
        self::assertMatchesRegularExpression('/default\s+1/', $output);
        self::assertMatchesRegularExpression('/total\s+3/', $output);
    }

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private static function invoke(InMemoryQueue $queue, array $argv): array
    {
        $capture = new CommandOutput();
        $code = new StatsCommand($queue, $capture->output)->run(CommandArguments::parse($argv));

        self::assertSame('', $capture->takeStderr());

        return [$code, $capture->takeStdout()];
    }
}
