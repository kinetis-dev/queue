<?php

declare(strict_types=1);

namespace Kinetis\Queue;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;

/**
 * Declared via extra.kinetis in this package's composer.json and run by
 * the framework ahead of the application's own bootstrap.php: with
 * QUEUE_CONNECTION configured, QueueInterface is bound to the selected
 * backend, so application code constructor-injects it and push()es jobs
 * with zero bootstrap code of its own. Without QUEUE_CONNECTION this
 * stays inert — "no queue" is a configuration, not an error.
 *
 * The application's bootstrap.php runs after this and wins on the
 * binding — an app running different queues on different backends still
 * registers its distinct concrete instances exactly as {@see QueueInterface}'s
 * docs describe.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->get('QUEUE_CONNECTION') === null) {
            return;
        }

        $app->instance(QueueInterface::class, QueueFactory::fromConfig($config));
    }
}
