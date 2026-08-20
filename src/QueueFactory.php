<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Queue\Exception\QueueUnavailableException;

/**
 * Builds the queue backend `QUEUE_CONNECTION` selects — the one shared
 * construction path behind both this package's `PackageBootstrap`
 * (binding `QueueInterface` for application-side `push()`) and the
 * `kinetis queue:work` command.
 *
 * `QUEUE_CONNECTION` has no default, deliberately — the same reasoning
 * `kinetis/migrations` applies to `DB_CONNECTION`: guessing wrong would
 * run against the wrong backend with no warning at all.
 * `QUEUE_CONNECTION_NAME` selects which named connection (see
 * `Config::scopedKey()`) the backend uses.
 *
 * Every backend lives in its own package — `redis` and `sql` are exactly
 * as optional as `sqs`/`rabbitmq`; this class never depends on any of
 * the four directly, only on the `class_exists()`-gated factory class
 * each one exposes, the same pattern `RuntimeDetector` uses for the
 * optional `kinetis/bref-adapter`. `kinetis/queue` itself only carries
 * `QueueInterface`, the worker, the CLI commands, and this dispatcher —
 * an application wanting Redis or SQL installs `kinetis/queue-redis`/
 * `kinetis/queue-sql` explicitly, the same as SQS or RabbitMQ.
 */
final class QueueFactory
{
    private const string REDIS_FACTORY_CLASS = 'Kinetis\QueueRedis\RedisQueueFactory';

    private const string SQL_FACTORY_CLASS = 'Kinetis\QueueSql\SqlQueueFactory';

    private const string SQS_FACTORY_CLASS = 'Kinetis\QueueSqs\SqsQueueFactory';

    private const string RABBITMQ_FACTORY_CLASS = 'Kinetis\QueueRabbitMq\RabbitMqQueueFactory';

    public static function fromConfig(Config $config): QueueInterface
    {
        $connection = $config->required('QUEUE_CONNECTION');
        $connectionName = $config->string('QUEUE_CONNECTION_NAME', 'default');

        return match ($connection) {
            'redis' => self::build($config, $connectionName, 'redis', 'kinetis/queue-redis', self::REDIS_FACTORY_CLASS),
            'sql' => self::build($config, $connectionName, 'sql', 'kinetis/queue-sql', self::SQL_FACTORY_CLASS),
            'sqs' => self::build($config, $connectionName, 'sqs', 'kinetis/queue-sqs', self::SQS_FACTORY_CLASS),
            'rabbitmq' => self::build($config, $connectionName, 'rabbitmq', 'kinetis/queue-rabbitmq', self::RABBITMQ_FACTORY_CLASS),
            default => throw new InvalidArgumentException(
                'QUEUE_CONNECTION must be "redis", "sql", "sqs", or "rabbitmq".',
            ),
        };
    }

    /**
     * One call site per backend (see fromConfig()), each passing its own
     * single factory-class constant — never a union of all four at once
     * — which is what keeps this file's own PHPStan run from resolving
     * the class_exists() check below as something it can already prove
     * false for every branch simultaneously, the same false positive a
     * combined array/match of all four constants at once would produce.
     */
    private static function build(
        Config $config,
        string $connectionName,
        string $backend,
        string $package,
        string $factoryClass,
    ): QueueInterface {
        if (!class_exists($factoryClass)) {
            throw QueueUnavailableException::missingBackendPackage($backend, $package);
        }

        /** @var QueueInterface */
        return $factoryClass::fromConfig($config, $connectionName);
    }
}
