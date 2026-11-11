<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Queue\QueueInterface;

/**
 * Reports how many jobs are waiting on each queue — the "is this backing
 * up?" question, answered against the QueueInterface binding: the
 * connection PackageBootstrap builds, or the application's own queue.
 *
 * Counts exclude jobs a worker currently holds, and include jobs still
 * inside their push() delay; see {@see QueueInterface::size()}. Some
 * backends report an estimate rather than an exact figure, which is what
 * this is for — alerting and eyeballing, not branching.
 */
final readonly class StatsCommand
{
    public function __construct(
        private QueueInterface $queue,
        private Output $output,
    ) {}

    #[Command('queue:stats', description: 'Show how many jobs are waiting. --queue=high,default selects which.')]
    public function run(CommandArguments $arguments): int
    {
        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        $width = max(array_map('strlen', [...$queues, 'QUEUE']));
        $this->output->line(str_pad('QUEUE', $width) . '  WAITING');

        $total = 0;

        foreach ($queues as $queue) {
            $size = $this->queue->size($queue);
            $total += $size;
            $this->output->line(str_pad($queue, $width) . '  ' . $size);
        }

        if (count($queues) > 1) {
            $this->output->line(str_repeat('-', $width + 9));
            $this->output->line(str_pad('total', $width) . '  ' . $total);
        }

        return 0;
    }
}
