<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\QueueInterface;

/**
 * Reports how many jobs are waiting on each queue — the "is this backing
 * up?" question, answered against whichever backend QUEUE_CONNECTION
 * selects.
 *
 * Counts exclude jobs a worker currently holds, and include jobs still
 * inside their push() delay; see {@see QueueInterface::size()}. Some
 * backends report an estimate rather than an exact figure, which is what
 * this is for — alerting and eyeballing, not branching.
 */
final readonly class StatsCommand
{
    /** @param resource $output */
    public function __construct(
        private QueueInterface $queue,
        private mixed $output = \STDOUT,
    ) {}

    #[Command('queue:stats', description: 'Show how many jobs are waiting. --queue=high,default selects which.')]
    public function run(CommandArguments $arguments): int
    {
        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        $width = max(array_map('strlen', [...$queues, 'QUEUE']));
        $this->line(str_pad('QUEUE', $width) . '  WAITING');

        $total = 0;

        foreach ($queues as $queue) {
            $size = $this->queue->size($queue);
            $total += $size;
            $this->line(str_pad($queue, $width) . '  ' . $size);
        }

        if (count($queues) > 1) {
            $this->line(str_repeat('-', $width + 9));
            $this->line(str_pad('total', $width) . '  ' . $total);
        }

        return 0;
    }

    private function line(string $text): void
    {
        fwrite($this->output, $text . "\n");
    }
}
