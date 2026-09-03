<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Console\WorkCommand;
use Kinetis\Queue\Tests\Fixtures\NeverCalledQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        ];
    }

    /**
     * Both config bounds are validated before any startup output and
     * before the queue backend is ever touched — proven here two ways at
     * once: the "started" line never reaches the output stream, and
     * NeverCalledQueue throws its own distinct exception if run() ever
     * reaches the point of constructing a real QueueWorker around it,
     * which would surface as this test failing with the wrong exception
     * type rather than silently passing.
     */
    #[DataProvider('invalidConfigCases')]
    public function test_invalid_config_produces_no_started_line_and_never_touches_the_queue(string $key, string $value): void
    {
        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $app = new AppScope();
        $app->boot();

        $command = new WorkCommand(
            $app->createRequestScope(),
            new NeverCalledQueue(),
            new Config([$key => $value]),
            $output,
        );

        try {
            $command->run(CommandArguments::parse([]));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected — the whole point of this test. NeverCalledQueue
            // would throw a different exception type if the ordering
            // were broken, which this catch block deliberately does not
            // swallow.
        }

        rewind($output);
        self::assertSame('', (string) stream_get_contents($output));
    }
}
