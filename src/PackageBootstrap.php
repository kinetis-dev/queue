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
 * the framework ahead of the application's own bootstrap.php: with
 * QUEUE_CONNECTION configured, QueueInterface is bound to the selected
 * backend, so application code constructor-injects it and push()es jobs
 * with zero bootstrap code of its own. Without QUEUE_CONNECTION this
 * stays inert — "no queue" is a configuration, not an error, and core's
 * own synchronous ListenerInvokerInterface default stands.
 *
 * All three bindings are factories, resolved on first use rather than
 * here, the same shape kinetis/storage's own bootstrap takes: an
 * application that never injects a queue never builds a backend, and an
 * application whose bootstrap.php binds its own QueueInterface never
 * builds the one QUEUE_CONNECTION names either.
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
        if ($config->get('QUEUE_CONNECTION') === null) {
            return;
        }

        $app->bind(QueueInterface::class, static fn (): QueueInterface => QueueFactory::fromConfig($config));

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
