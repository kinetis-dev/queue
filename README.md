<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/queue</strong>
  <br>
  <strong>A backend-agnostic background job queue for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/v/kinetis/queue?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/dt/kinetis/queue" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/php-v/kinetis/queue" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/l/kinetis/queue" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

One `Kinetis\Queue\QueueInterface` — push a job from application code, a
separate `kinetis queue:work` worker process pops and runs it. Named,
priority-ordered queues, bounded retries (`maxAttempts`, defaulting to
no retries at all), and named connections come built in. A job given up
on is logged with its arguments, minus any constructor parameter marked
`Kinetis\Queue\Attributes\Sensitive`. Every backend — Redis
(`kinetis/queue-redis`), SQL (`kinetis/queue-sql`), Amazon SQS
(`kinetis/queue-sqs`), and RabbitMQ (`kinetis/queue-rabbitmq`) — lives in
its own separate package; this one carries only the contract, the
worker, and the CLI commands.

```php
use Kinetis\Queue\Job;
use Kinetis\Queue\QueueInterface;

final readonly class SendWelcomeEmail implements Job
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->email, "Welcome, {$this->name}!");
    }
}

$queue->push(new SendWelcomeEmail($email, $name), maxAttempts: 3);
```

```sh
vendor/bin/kinetis queue:work --queue=high,default
```

## Provides

Installing this package is what opts it in — it registers the
following automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[kinetis.dev/docs/cli.html](https://kinetis.dev/docs/cli.html)):

- **Commands** on `vendor/bin/kinetis`: `queue:work` (the worker loop,
  stopping gracefully on SIGTERM once the job in flight finishes),
  `queue:stats` (how many jobs are waiting), and `queue:clear`
  (discard waiting jobs, requires `--force`).
- **Service binding**: with `QUEUE_CONNECTION` set, `QueueInterface` is
  bound to the selected backend before your own `bootstrap.php` runs —
  your registration wins on the same binding. Inert when
  `QUEUE_CONNECTION` is unset.

Nothing else — no routes, middleware, event listeners, or MCP tools.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config` — by
`kinetis queue:work` and by this package's bootstrap, which binds
`QueueInterface` to the selected backend with no application wiring.
Each backend's own connection details are documented in that backend's
own package (`kinetis/queue-redis`, `kinetis/queue-sql`,
`kinetis/queue-sqs`, `kinetis/queue-rabbitmq`) — this package installs
none of them, so picking `QUEUE_CONNECTION=redis` (say) without also
`composer require kinetis/queue-redis` fails clearly, naming the
package to install.

| Key | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | *(required)* | `redis`, `sql`, `sqs`, or `rabbitmq` — each needs its own package installed. |
| `QUEUE_CONNECTION_NAME` | `default` | Which named connection block the backend uses. |
| `QUEUE_MAX_ATTEMPTS` | `0` | Worker-level default attempts cap (`0` = no retries); a job's own `push(maxAttempts: ...)` wins. |
| `QUEUE_POLL_TIMEOUT` | `5` | Seconds per `pop()` wait. |

Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/queue
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[kinetis.dev/docs/queue.html](https://kinetis.dev/docs/queue.html).

## License

MIT — see [LICENSE](../../LICENSE).
