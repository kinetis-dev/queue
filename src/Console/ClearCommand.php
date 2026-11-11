<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Exception\QueueNotClearableException;
use Kinetis\Queue\QueueContract;
use Kinetis\Queue\QueueInterface;

/**
 * Discards every job waiting on a queue.
 *
 * Requires `--force`: this deletes real work with no dead-letter copy to
 * restore from, and a queue name is easy to mistype into one that holds
 * something. Jobs a worker has already reserved are untouched — those
 * belong to that worker until it finishes with them.
 *
 * Takes the bound QueueInterface, which does not carry clear(), so this
 * is where the capability is checked at runtime: a backend that does not
 * implement {@see ClearableQueueInterface} (Amazon SQS is the one
 * Kinetis ships) is named on stderr along with what to do instead, and
 * the command exits 1 having touched no queue at all — the same wording
 * {@see QueueNotClearableException} carries for an application asking
 * the container for the capability. Application code names
 * ClearableQueueInterface in its own type and gets that answer at
 * resolution instead, with nothing to check itself.
 *
 * Every name in --queue is parsed and validated as one list before the
 * first queue is cleared, through the same
 * {@see QueueContract::assertValidQueueList()} pop() applies —
 * validating name by name would let `--queue=default,not a name`
 * discard `default` before rejecting the rest.
 */
final readonly class ClearCommand
{
    public function __construct(
        private QueueInterface $queue,
        private Output $output,
    ) {}

    #[Command('queue:clear', description: 'Discard waiting jobs: queue:clear --queue=default --force')]
    public function run(CommandArguments $arguments): int
    {
        // Checked ahead of --force: on a backend that cannot clear at
        // all, "add --force" would be advice that never works.
        if (!$this->queue instanceof ClearableQueueInterface) {
            $this->output->error(QueueNotClearableException::describe($this->queue));

            return 1;
        }

        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        QueueContract::assertValidQueueList($queues);

        if (!$arguments->hasOption('force')) {
            $this->output->error('Refusing to clear ' . implode(', ', $queues) . ' without --force.');
            $this->output->error('Discarded jobs cannot be recovered.');

            return 1;
        }

        foreach ($queues as $queue) {
            $this->output->line("Cleared {$this->queue->clear($queue)} job(s) from \"{$queue}\".");
        }

        return 0;
    }
}
