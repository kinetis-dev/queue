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

Redis and SQL (MySQL/Postgres) backends included, behind one
`Kinetis\Queue\QueueInterface` — push a job from application code, a
separate `kinetis queue:work` worker process pops and runs it. Named,
priority-ordered queues, bounded retries (`maxAttempts`, defaulting to
no retries at all), and named connections come built in. Amazon SQS is a
third backend, in the separate `kinetis/queue-sqs`.

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
[docs.kinetis.dev/cli.html](https://docs.kinetis.dev/cli.html)):

- **Command**: `queue:work` on `vendor/bin/kinetis` — the worker loop.
- **Service binding**: with `QUEUE_CONNECTION` set, `QueueInterface` is
  bound to the selected backend before your own `bootstrap.php` runs —
  your registration wins on the same binding. Inert when
  `QUEUE_CONNECTION` is unset.

Nothing else — no routes, middleware, event listeners, or MCP tools.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config` — by
`kinetis queue:work` and by this package's bootstrap, which binds
`QueueInterface` to the selected backend with no application wiring.
The backend's own connection details come from the keys that backend
already documents: `REDIS_*` (`kinetis/cache-redis`'s convention),
`DB_*` (`kinetis/persistence`), or the `QUEUE_SQS_*`/`QUEUE_RABBITMQ_*`
keys in `kinetis/queue-sqs`/`kinetis/queue-rabbitmq`.

| Key | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | *(required)* | `redis`, `sql`, `sqs` (needs `kinetis/queue-sqs`), or `rabbitmq` (needs `kinetis/queue-rabbitmq`). |
| `QUEUE_CONNECTION_NAME` | `default` | Which named `REDIS_*`/`DB_*` block the backend uses. |
| `QUEUE_MAX_ATTEMPTS` | `0` | Worker-level default attempts cap (`0` = no retries); a job's own `push(maxAttempts: ...)` wins. |
| `QUEUE_POLL_TIMEOUT` | `5` | Seconds per `pop()` wait. |
| `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | — | SQL backend only (scoped): reclaim a crashed worker's reserved job after this long; unset means never. |

Full reference across every package:
[docs.kinetis.dev/config.html](https://docs.kinetis.dev/config.html).

## Installation

```sh
composer require kinetis/queue
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/queue.html](https://docs.kinetis.dev/queue.html).

## License

MIT — see [LICENSE](../../LICENSE).
