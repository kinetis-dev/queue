<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\RequestScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\QueueWorker;

/**
 * The queue worker loop as a `kinetis` command, discovered via this
 * package's extra.kinetis scan root. Returns 0 once SIGTERM or SIGINT
 * has stopped the loop and the job in flight has finished — which needs
 * ext-pcntl; without it the loop cannot observe signals at all, and the
 * startup warning below is the operator's only cue. The queue backend resolves through
 * the container — bound by this package's own PackageBootstrap from
 * QUEUE_CONNECTION, or by the application's bootstrap.php overriding it.
 *
 * Constructor-injects RequestScope (safe per #[Command] dispatch's own
 * per-invocation scope; see BearerAuthMiddleware's identical pattern for
 * why this is never registered on AppScope directly) purely to reach the
 * booted application container QueueWorker hands each job.
 */
final readonly class WorkCommand
{
    public function __construct(
        private RequestScope $scope,
        private QueueInterface $queue,
        private Config $config,
    ) {}

    #[Command('queue:work', description: 'Run the queue worker loop. --queue=high,default sets priority order.')]
    public function run(CommandArguments $arguments): int
    {
        // Priority is expressed by list order, not a numeric per-job
        // score. Defaults to ['default'] when the flag is absent.
        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        $pollTimeoutSeconds = $this->config->int('QUEUE_POLL_TIMEOUT', 5);

        // Defaults to 0 (no retries) when unset. A job's own
        // push(maxAttempts: ...) always overrides this.
        $defaultMaxAttempts = $this->config->int('QUEUE_MAX_ATTEMPTS', 0);

        fwrite(STDOUT, 'Queue worker started, listening on: ' . implode(', ', $queues) . "\n");

        if (!QueueWorker::supportsGracefulShutdown()) {
            fwrite(
                STDERR,
                "Warning: ext-pcntl is not loaded, so SIGTERM cannot stop this worker between jobs. "
                . "A deploy will interrupt whatever job is running. Install pcntl to avoid that.\n",
            );
        }
        new QueueWorker($this->scope->appScope(), $this->queue, $defaultMaxAttempts)->run($pollTimeoutSeconds, $queues);
        fwrite(STDOUT, "Queue worker stopped.\n");

        return 0;
    }
}
