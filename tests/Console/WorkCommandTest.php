<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Console\WorkCommand;
use Kinetis\Queue\Exception\QueueUnavailableException;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\Tests\Fixtures\NeverCalledQueue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkCommandTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function invalidConfigCases(): array
    {
        return [
            'negative QUEUE_MAX_ATTEMPTS' => ['QUEUE_MAX_ATTEMPTS', '-1'],
            'negative QUEUE_POLL_TIMEOUT' => ['QUEUE_POLL_TIMEOUT', '-1'],
            // KINETIS-64: 0 is a valid QueueInterface::pop() timeout on its
            // own (block with no deadline), but a persistent queue:work
            // loop needs a finite, positive value to periodically regain
            // control and observe a shutdown signal — see
            // QueueWorker::assertValidPollTimeout()'s own docblock.
            'zero QUEUE_POLL_TIMEOUT' => ['QUEUE_POLL_TIMEOUT', '0'],
            'negative QUEUE_RETRY_BASE_DELAY_SECONDS' => ['QUEUE_RETRY_BASE_DELAY_SECONDS', '-1'],
            // Above the 900-second ceiling the computed backoff is capped
            // at, so it could only ever produce the cap — a deployment
            // asking for something this worker will not do.
            'over-range QUEUE_RETRY_BASE_DELAY_SECONDS' => ['QUEUE_RETRY_BASE_DELAY_SECONDS', '901'],
        ];
    }

    /**
     * Every config bound is validated before any startup output and
     * before the queue binding is ever resolved — proven here two ways at
     * once: the "started" line never reaches the output stream, and the
     * binding throws its own RuntimeException if run() resolves it, which
     * would surface as this test failing with the wrong exception type
     * rather than silently passing.
     */
    #[DataProvider('invalidConfigCases')]
    public function test_invalid_config_produces_no_started_line_and_never_resolves_the_queue(string $key, string $value): void
    {
        $output = self::memoryStream();

        try {
            self::command(new Config([$key => $value]), $output)->run(CommandArguments::parse([]));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected. The binding's RuntimeException is not caught here,
            // so resolving it fails the test.
        }

        self::assertSame('', self::contents($output));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConnectionOptions(): iterable
    {
        yield 'bare' => ['--connection', '--connection needs a value: --connection=<name>.'];
        yield 'empty' => ['--connection=', '--connection needs a value: --connection=<name>.'];
        yield 'uppercase' => [
            '--connection=Jobs',
            'Invalid connection name "Jobs" from --connection: a connection name is lowercase ASCII letters and '
            . 'digits, starting with a letter (^[a-z][a-z0-9]*$).',
        ];
    }

    #[DataProvider('invalidConnectionOptions')]
    public function test_an_invalid_connection_option_fails_before_output_and_resolution(string $option, string $message): void
    {
        $output = self::memoryStream();

        try {
            self::command(new Config(['QUEUE_JOBS_CONNECTION' => 'redis']), $output)
                ->run(CommandArguments::parse([$option]));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame('', self::contents($output));
    }

    /**
     * The named connection is built by QueueFactory — reaching it for the
     * uninstalled backend, naming the scoped selector, is the proof — and
     * the default binding, which would throw its own message, is never
     * resolved.
     */
    public function test_a_named_connection_is_built_without_resolving_the_default_binding(): void
    {
        $output = self::memoryStream();

        try {
            self::command(new Config(['QUEUE_JOBS_CONNECTION' => 'redis']), $output)
                ->run(CommandArguments::parse(['--connection=jobs']));
            self::fail('Expected a QueueUnavailableException.');
        } catch (QueueUnavailableException $exception) {
            self::assertSame(
                'Cannot use QUEUE_JOBS_CONNECTION="redis": install "kinetis/queue-redis" to enable it.',
                $exception->getMessage(),
            );
        }

        self::assertSame('', self::contents($output));
    }

    /**
     * Without --connection the worker runs the queue the application
     * bound: NeverCalledQueue's pop() failure propagating out of the
     * loop is what proves this instance, and no other, was the one run.
     */
    public function test_no_connection_option_runs_the_application_bound_queue(): void
    {
        $output = self::memoryStream();

        $app = new AppScope();
        $app->instance(QueueInterface::class, new NeverCalledQueue());
        $app->boot();

        $command = new WorkCommand($app->createRequestScope(), new Config([]), $output, self::memoryStream());

        try {
            $command->run(CommandArguments::parse(['--queue=high,default']));
            self::fail('Expected the application queue to be popped.');
        } catch (LogicException $exception) {
            self::assertSame('The queue backend must not be touched.', $exception->getMessage());
        }

        self::assertSame("Queue worker started, listening on: high, default\n", self::contents($output));
    }

    /**
     * @param resource $output
     */
    private static function command(Config $config, mixed $output): WorkCommand
    {
        $app = new AppScope();
        $app->bind(QueueInterface::class, static function (): QueueInterface {
            throw new RuntimeException('The default queue binding must not be resolved.');
        });
        $app->boot();

        return new WorkCommand($app->createRequestScope(), $config, $output, self::memoryStream());
    }

    /**
     * @return resource
     */
    private static function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
