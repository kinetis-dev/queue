<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Queue\Exception\QueueUnavailableException;
use Kinetis\Queue\QueueFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every one of the four backend packages is genuinely uninstalled in
 * this package's own test environment — the "which side can prove which
 * branch" split this project applies to every other class_exists()-gated
 * dispatch: the missing-package path is what core's own suite proves,
 * the installed-and-working path belongs to each backend package's own
 * suite (RedisQueueFactory/SqlQueueFactory/SqsClientFactory/
 * RabbitMqClientFactory).
 */
final class QueueFactoryTest extends TestCase
{
    public function test_an_unrecognized_connection_is_rejected(): void
    {
        $config = new Config(['QUEUE_CONNECTION' => 'mongodb']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('QUEUE_CONNECTION must be "redis", "sql", "sqs", or "rabbitmq".');
        QueueFactory::fromConfig($config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function backends(): iterable
    {
        yield 'redis' => ['redis', 'kinetis/queue-redis'];
        yield 'sql' => ['sql', 'kinetis/queue-sql'];
        yield 'sqs' => ['sqs', 'kinetis/queue-sqs'];
        yield 'rabbitmq' => ['rabbitmq', 'kinetis/queue-rabbitmq'];
    }

    #[DataProvider('backends')]
    public function test_a_backend_whose_package_is_not_installed_names_it(string $connection, string $package): void
    {
        $config = new Config(['QUEUE_CONNECTION' => $connection]);

        $this->expectException(QueueUnavailableException::class);
        $this->expectExceptionMessage("install \"{$package}\"");
        QueueFactory::fromConfig($config);
    }
}
