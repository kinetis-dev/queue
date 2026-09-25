<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Queue\Exception\QueueUnavailableException;

/**
 * Builds the queue backend a named connection selects — the one shared
 * construction path behind this package's `PackageBootstrap` (binding
 * `QueueInterface` for application-side `push()`),
 * `queue:work --connection=<name>`, and an application building several
 * queues itself.
 *
 * `$connection` names the connection whose selector
 * (`Config::scopedKey('QUEUE_CONNECTION', $connection)`: `QUEUE_CONNECTION`
 * for `default`, `QUEUE_JOBS_CONNECTION` for `jobs`) picks the backend,
 * and the same name selects that backend's own scoped keys. A named
 * selector has no fallback to the unscoped one, and none of them has a
 * default — the same reasoning `kinetis/migrations` applies to
 * `DB_CONNECTION`: guessing wrong would run against the wrong backend
 * with no warning at all. Like the database bridge's factory, this
 * class does not validate the name; a caller taking it from outside the
 * application does.
 *
 * Every backend lives in its own package — `redis` and `sql` are exactly
 * as optional as `sqs`/`rabbitmq`; this class never depends on any of
 * the four directly, only on the `class_exists()`-gated factory class
 * each one exposes, the same pattern `RuntimeDetector` uses for the
 * optional `kinetis/bref-adapter`. `kinetis/queue` itself only carries
 * `QueueInterface`, the worker, the CLI commands, and this dispatcher —
 * an application wanting Redis or SQL installs `kinetis/queue-redis`/
 * `kinetis/queue-sql` explicitly, the same as SQS or RabbitMQ.
 *
 * The return type is `QueueInterface` — the contract every backend
 * honors identically. Capabilities beyond it vary by backend and are
 * declared by separate interfaces, so a caller needing one checks for it
 * on the returned instance (`ClearableQueueInterface`, which the `sqs`
 * backend does not satisfy, or `DisposableQueueInterface`, whose
 * dispose() the caller registers on the scope that owns the result)
 * rather than assuming every backend has it. Each backend's own factory
 * returns the narrowest type its backend declares, since a caller
 * reaching one of those has already named the backend.
 * `PackageBootstrap` binds each capability against the application's
 * own resolved `QueueInterface`, not against what this class returns.
 */
final class QueueFactory
{
    private const string REDIS_FACTORY_CLASS = 'Kinetis\QueueRedis\RedisQueueFactory';

    private const string SQL_FACTORY_CLASS = 'Kinetis\QueueSql\SqlQueueFactory';

    private const string SQS_FACTORY_CLASS = 'Kinetis\QueueSqs\SqsQueueFactory';

    private const string RABBITMQ_FACTORY_CLASS = 'Kinetis\QueueRabbitMq\RabbitMqQueueFactory';

    public static function fromConfig(Config $config, string $connection = 'default'): QueueInterface
    {
        $key = Config::scopedKey('QUEUE_CONNECTION', $connection);

        return match ($config->required($key)) {
            'redis' => self::build($config, $connection, $key, 'redis', 'kinetis/queue-redis', self::REDIS_FACTORY_CLASS),
            'sql' => self::build($config, $connection, $key, 'sql', 'kinetis/queue-sql', self::SQL_FACTORY_CLASS),
            'sqs' => self::build($config, $connection, $key, 'sqs', 'kinetis/queue-sqs', self::SQS_FACTORY_CLASS),
            'rabbitmq' => self::build($config, $connection, $key, 'rabbitmq', 'kinetis/queue-rabbitmq', self::RABBITMQ_FACTORY_CLASS),
            default => throw new InvalidArgumentException(
                "{$key} must be \"redis\", \"sql\", \"sqs\", or \"rabbitmq\".",
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
        string $connection,
        string $key,
        string $backend,
        string $package,
        string $factoryClass,
    ): QueueInterface {
        if (!class_exists($factoryClass)) {
            throw QueueUnavailableException::missingBackendPackage($key, $backend, $package);
        }

        /** @var QueueInterface */
        return $factoryClass::fromConfig($config, $connection);
    }
}
