<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\Exception\QueueNotClearableException;
use Psr\Container\ContainerInterface;

/**
 * Declared via extra.kinetis in this package's composer.json and run by
 * the framework ahead of the application's own bootstrap.php: with the
 * selector of the connection QUEUE_CONNECTION_NAME names configured
 * (`QUEUE_CONNECTION` for `default`, which an unset or blank name
 * selects; `QUEUE_JOBS_CONNECTION` for `jobs` — see {@see QueueFactory}),
 * QueueInterface is bound to that connection's backend, so application
 * code constructor-injects it and push()es jobs with zero bootstrap code
 * of its own. Without that selector this stays inert — "no queue" is a
 * configuration, not an error, and core's own synchronous
 * ListenerInvokerInterface default stands. A malformed name is an error
 * and throws; see {@see QueueContract::assertValidConnectionName()}.
 *
 * All three bindings are factories, resolved on first use rather than
 * here, the same shape kinetis/storage's own bootstrap takes: an
 * application that never injects a queue never builds a backend, and an
 * application whose bootstrap.php binds its own QueueInterface never
 * builds the one the selector names either.
 *
 * ClearableQueueInterface and ListenerInvokerInterface both resolve
 * through QueueInterface, so they always answer with the backend the
 * application ends up with — the application's bootstrap.php runs after
 * this and wins on that binding, and an app running different queues on
 * different backends still registers its own concrete instances exactly
 * as {@see QueueInterface}'s docs describe. Binding the factory's own
 * instance here instead would hand a consumer a backend nothing else in
 * the application uses.
 *
 * Where the resolved queue does not declare the clearing capability,
 * asking for it raises {@see QueueNotClearableException}, naming the
 * backend.
 *
 * A backend built here owns the connection its factory opened, so
 * resolving one that declares {@see DisposableQueueInterface} also
 * registers its dispose() on the application scope. Nothing else is
 * registered: an application's own QueueInterface binding stops this
 * factory from running at all, and closing the queue it bound instead
 * belongs to whoever opened that one.
 *
 * ListenerInvokerInterface is what makes Kinetis\Events\ShouldQueue mean
 * what it says: a configured queue is the whole of "queue my queued
 * listeners", with no second stanza to remember. Core binds its own
 * synchronous default in AppScope::boot(), which runs after every
 * package bootstrap and only when nothing is bound yet, so this
 * registration wins over it — and an application binding either
 * interface in its own bootstrap.php wins over this one, since that
 * runs later still.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $connection = $config->string('QUEUE_CONNECTION_NAME', '');

        // Validated before it derives the gate: a malformed name would
        // otherwise read a selector nobody set and leave the queue
        // silently unbound, running queued listeners inline.
        if ($connection === '') {
            $connection = 'default';
        } else {
            QueueContract::assertValidConnectionName($connection, 'QUEUE_CONNECTION_NAME');
        }

        if ($config->get(Config::scopedKey('QUEUE_CONNECTION', $connection)) === null) {
            return;
        }

        $app->bind(QueueInterface::class, static function (AppScope $app) use ($config, $connection): QueueInterface {
            $queue = QueueFactory::fromConfig($config, $connection);

            // This binding opened the backend's connection, so this
            // binding closes it when the worker ends — registered
            // against the one instance built here, never against an
            // application's own queue, which never reaches this closure
            // at all.
            if ($queue instanceof DisposableQueueInterface) {
                $app->onDispose($queue->dispose(...));
            }

            return $queue;
        });

        $app->bind(
            ClearableQueueInterface::class,
            static function (ContainerInterface $container): ClearableQueueInterface {
                /** @var QueueInterface $queue the binding above, or whatever the application replaced it with */
                $queue = $container->get(QueueInterface::class);

                if (!$queue instanceof ClearableQueueInterface) {
                    throw QueueNotClearableException::forBackend($queue);
                }

                return $queue;
            },
        );

        $app->bind(
            ListenerInvokerInterface::class,
            static function (ContainerInterface $container): ListenerInvokerInterface {
                /** @var QueueInterface $queue the binding above, or whatever the application replaced it with */
                $queue = $container->get(QueueInterface::class);

                return new QueuedListenerInvoker($queue);
            },
        );
    }
}
