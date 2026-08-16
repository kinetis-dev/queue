<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\QueueInterface;

/**
 * Discards every job waiting on a queue.
 *
 * Requires `--force`: this deletes real work with no dead-letter copy to
 * restore from, and a queue name is easy to mistype into one that holds
 * something. Jobs a worker has already reserved are untouched — those
 * belong to that worker until it finishes with them.
 */
final readonly class ClearCommand
{
    /** @param resource $output */
    public function __construct(
        private QueueInterface $queue,
        private mixed $output = \STDOUT,
    ) {}

    #[Command('queue:clear', description: 'Discard waiting jobs: queue:clear --queue=default --force')]
    public function run(CommandArguments $arguments): int
    {
        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        if (!$arguments->hasOption('force')) {
            $this->line('Refusing to clear ' . implode(', ', $queues) . ' without --force.');
            $this->line('Discarded jobs cannot be recovered.');

            return 1;
        }

        foreach ($queues as $queue) {
            $this->line("Cleared {$this->queue->clear($queue)} job(s) from \"{$queue}\".");
        }

        return 0;
    }

    private function line(string $text): void
    {
        fwrite($this->output, $text . "\n");
    }
}
