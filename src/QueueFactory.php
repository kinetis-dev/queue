<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Queue\Exception\QueueUnavailableException;
use Kinetis\SimpleCache\RedisSimpleCache;

use function Amp\Redis\createRedisClient;

/**
 * Builds the queue backend QUEUE_CONNECTION selects — the one shared
 * construction path behind both this package's PackageBootstrap (binding
 * QueueInterface for application-side push()) and the `kinetis
 * queue:work` command.
 *
 * QUEUE_CONNECTION has no default, deliberately — the same reasoning
 * kinetis/migrations applies to DB_CONNECTION: guessing wrong would run
 * against the wrong backend with no warning at all.
 * QUEUE_CONNECTION_NAME selects which named REDIS_ or DB_ connection
 * block (see Config::scopedKey()) the backend uses.
 *
 * The sqs/rabbitmq branches reference their backend classes as plain
 * strings gated by class_exists() — kinetis/queue never depends on
 * kinetis/queue-sqs or kinetis/queue-rabbitmq; the same pattern
 * RuntimeDetector uses for the optional kinetis/bref-adapter.
 */
final class QueueFactory
{
    public static function fromConfig(Config $config): QueueInterface
    {
        $connection = $config->required('QUEUE_CONNECTION');
        $connectionName = $config->string('QUEUE_CONNECTION_NAME', 'default');

        return match ($connection) {
            'redis' => self::redis($config, $connectionName),
            'sql' => self::sql($config, $connectionName),
            'sqs' => self::optional(
                $config,
                $connectionName,
                'sqs',
                'kinetis/queue-sqs',
                'Kinetis\QueueSqs\SqsClientFactory',
                'Kinetis\QueueSqs\SqsQueue',
                'QUEUE_SQS_QUEUE_PREFIX',
            ),
            'rabbitmq' => self::optional(
                $config,
                $connectionName,
                'rabbitmq',
                'kinetis/queue-rabbitmq',
                'Kinetis\QueueRabbitMq\RabbitMqClientFactory',
                'Kinetis\QueueRabbitMq\RabbitMqQueue',
                'QUEUE_RABBITMQ_QUEUE_PREFIX',
            ),
            default => throw new InvalidArgumentException(
                'QUEUE_CONNECTION must be "redis", "sql", "sqs", or "rabbitmq".',
            ),
        };
    }

    private static function redis(Config $config, string $connectionName): QueueInterface
    {
        $redisConfig = RedisSimpleCache::buildRedisConfig($config, $connectionName);

        if ($redisConfig === null) {
            throw new InvalidArgumentException('REDIS_URL or REDIS_HOST must be set when QUEUE_CONNECTION=redis.');
        }

        return new RedisQueue(createRedisClient($redisConfig));
    }

    private static function sql(Config $config, string $connectionName): QueueInterface
    {
        // QUEUE_VISIBILITY_TIMEOUT_SECONDS has no default — absent means
        // SqlQueue's own behavior of a crashed worker's row staying
        // reserved forever, unchanged unless explicitly opted into.
        $visibilityTimeoutRaw = $config->string(Config::scopedKey('QUEUE_VISIBILITY_TIMEOUT_SECONDS', $connectionName), '');
        $visibilityTimeoutSeconds = $visibilityTimeoutRaw === '' ? null : (int) $visibilityTimeoutRaw;

        return new SqlQueue(SqlConnectionFactory::fromConfig($config, $connectionName), $visibilityTimeoutSeconds);
    }

    /**
     * $clientFactoryClass/$queueClass are plain strings, not
     * class-string: the classes belong to optional packages this one
     * never depends on, so they are unknown at analysis time — the
     * class_exists() gate below is the runtime guarantee.
     */
    private static function optional(
        Config $config,
        string $connectionName,
        string $backend,
        string $package,
        string $clientFactoryClass,
        string $queueClass,
        string $prefixKey,
    ): QueueInterface {
        if (!class_exists($clientFactoryClass)) {
            throw QueueUnavailableException::missingBackendPackage($backend, $package);
        }

        $queuePrefix = $config->string(Config::scopedKey($prefixKey, $connectionName), '');

        /** @var QueueInterface */
        return new $queueClass($clientFactoryClass::fromConfig($config, $connectionName), $queuePrefix);
    }
}
