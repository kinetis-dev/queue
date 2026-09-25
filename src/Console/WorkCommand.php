<?php

declare(strict_types=1);

namespace Kinetis\Queue\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\RequestScope;
use Kinetis\Queue\DisposableQueueInterface;
use Kinetis\Queue\QueueContract;
use Kinetis\Queue\QueueFactory;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\QueueWorker;

/**
 * The queue worker loop as a `kinetis` command, discovered via this
 * package's extra.kinetis scan root. Returns 0 once SIGTERM or SIGINT
 * has stopped the loop and the job in flight has finished — which needs
 * ext-pcntl; without it the loop cannot observe signals at all, and the
 * startup warning below is the operator's only cue.
 *
 * Without --connection the queue resolves through the container — bound
 * by this package's own PackageBootstrap, or by the application's
 * bootstrap.php overriding it. --connection=<name> builds that named
 * connection through QueueFactory instead and never resolves the
 * binding, so `--connection=default` means the connection the unscoped
 * keys describe, whatever the application bound. The queue built here
 * is this command's to close: a disposable one registers its dispose()
 * on the application scope, which the CLI disposes on every exit path.
 *
 * Constructor-injects RequestScope (safe per #[Command] dispatch's own
 * per-invocation scope; see BearerAuthMiddleware's identical pattern for
 * why this is never registered on AppScope directly) to resolve the
 * queue binding and reach the booted application container QueueWorker
 * hands each job.
 *
 * $output/$errorOutput are injectable (defaulting to STDOUT/STDERR) for
 * testability against php://memory — the same pattern StatsCommand/
 * ClearCommand already use.
 */
final readonly class WorkCommand
{
    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *     "resource" type and a readonly property requires one
     * @param resource $errorOutput
     */
    public function __construct(
        private RequestScope $scope,
        private Config $config,
        private mixed $output = STDOUT,
        private mixed $errorOutput = STDERR,
    ) {}

    #[Command(
        'queue:work',
        description: 'Run the queue worker loop. --queue=high,default sets priority order; --connection=<name> selects a named queue connection.',
    )]
    public function run(CommandArguments $arguments): int
    {
        // Priority is expressed by list order, not a numeric per-job
        // score. Defaults to ['default'] when the flag is absent.
        $queueOption = $arguments->option('queue');
        $queues = $queueOption === null
            ? ['default']
            : array_map('trim', explode(',', $queueOption));

        $connection = self::selectedConnection($arguments);

        $pollTimeoutSeconds = $this->config->int('QUEUE_POLL_TIMEOUT', 5);

        // Defaults to 0 (no retries) when unset. A job's own
        // push(maxAttempts: ...) always overrides this.
        $defaultMaxAttempts = $this->config->int('QUEUE_MAX_ATTEMPTS', 0);

        // The first retry's delay, doubling per attempt up to the
        // worker's own ceiling — see QueueWorker's own docblock.
        $retryBaseDelaySeconds = $this->config->int('QUEUE_RETRY_BASE_DELAY_SECONDS', 5);

        // All three validated through QueueWorker's own shared assertions
        // before any queue is resolved or built and before any startup
        // output — an invalid deployment must never open a connection or
        // print "started" (or the pcntl warning) and then fail.
        QueueWorker::assertValidDefaultMaxAttempts($defaultMaxAttempts);
        QueueWorker::assertValidRetryBaseDelay($retryBaseDelaySeconds);
        QueueWorker::assertValidPollTimeout($pollTimeoutSeconds);

        $queue = $this->queue($connection);

        fwrite($this->output, 'Queue worker started, listening on: ' . implode(', ', $queues) . "\n");

        if (!QueueWorker::supportsGracefulShutdown()) {
            fwrite(
                $this->errorOutput,
                "Warning: ext-pcntl is not loaded, so SIGTERM cannot stop this worker between jobs. "
                . "A deploy will interrupt whatever job is running. Install pcntl to avoid that.\n",
            );
        }
        new QueueWorker($this->scope->appScope(), $queue, $defaultMaxAttempts, $retryBaseDelaySeconds)
            ->run($pollTimeoutSeconds, $queues);
        fwrite($this->output, "Queue worker stopped.\n");

        return 0;
    }

    private static function selectedConnection(CommandArguments $arguments): ?string
    {
        if (!$arguments->hasOption('connection')) {
            return null;
        }

        $name = $arguments->option('connection');

        if ($name === null || $name === '') {
            throw new InvalidArgumentException('--connection needs a value: --connection=<name>.');
        }

        QueueContract::assertValidConnectionName($name, '--connection');

        return $name;
    }

    private function queue(?string $connection): QueueInterface
    {
        if ($connection === null) {
            /** @var QueueInterface */
            return $this->scope->get(QueueInterface::class);
        }

        $queue = QueueFactory::fromConfig($this->config, $connection);

        // Registered rather than disposed in a finally: a cleanup
        // failure must never replace the worker's own exception, and
        // the CLI disposes the application scope on every exit path.
        if ($queue instanceof DisposableQueueInterface) {
            $this->scope->appScope()->onDispose($queue->dispose(...));
        }

        return $queue;
    }
}
