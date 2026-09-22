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

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

One `Kinetis\Queue\QueueInterface` — push a job from application code, a
separate `kinetis queue:work` worker process pops and runs it. Named,
priority-ordered queues, bounded retries (`maxAttempts`, defaulting to
no retries at all) with an exponential backoff the backend holds the job
through, and named connections come built in. A job given up on is
logged with its arguments, minus any constructor parameter marked
`Kinetis\Queue\Attributes\Sensitive`. Every backend — Redis
([`kinetis/queue-redis`](https://github.com/kinetis-dev/queue-redis)), SQL ([`kinetis/queue-sql`](https://github.com/kinetis-dev/queue-sql)), Amazon SQS
([`kinetis/queue-sqs`](https://github.com/kinetis-dev/queue-sqs)), and RabbitMQ ([`kinetis/queue-rabbitmq`](https://github.com/kinetis-dev/queue-rabbitmq)) — lives in
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
  (discard waiting jobs, requires `--force`; refuses on a backend that
  cannot clear — see below).
- **Service bindings**: with `QUEUE_CONNECTION` set, `QueueInterface` is
  bound to the selected backend before your own `bootstrap.php` runs —
  your registration wins on the same binding — and both
  `ClearableQueueInterface` and core's
  `Kinetis\Events\ListenerInvokerInterface` are bound to whatever
  `QueueInterface` finally resolves to, yours included. That last one is
  what makes a listener marked `Kinetis\Events\ShouldQueue` actually
  queue, with no second stanza to write. All three are built on first
  use, so an application that never injects a queue builds no backend.
  A backend built that way owns its connection, and this package closes
  it when the worker ends — see below. Inert when `QUEUE_CONNECTION` is
  unset, leaving core's synchronous listener invoker in place.
- **Events**, dispatched by `queue:work` around every job's outcome —
  register a `#[Listener]` for whichever one you need:
  `Kinetis\Queue\Events\JobSucceeded`, `JobReleased` (a job failed but
  will retry), `JobFailedPermanently` (attempts exhausted), and
  `JobSettlementLost` (the backend refused the settlement because this
  worker's delivery was already over). See
  [kinetis.dev/docs/events.html](https://kinetis.dev/docs/events.html)
  for the full list across every package.

Nothing else — no routes, middleware, event listeners, or MCP tools.

## A queue owns the connection its factory opened

A queue backend lives for the whole worker, so its connection is opened
once and closed once, when the worker ends. That close lives on
`Kinetis\Queue\DisposableQueueInterface` — `dispose()`, extending
`QueueInterface`, declared by `kinetis/queue-redis`, `kinetis/queue-sql`
and `kinetis/queue-rabbitmq`. `kinetis/queue-sqs` does not declare it:
its transport is an HTTP client with no queue-owned connection to close.

Ownership travels with construction, not with the type. A backend's
`fromConfig()` opens the client or link it hands the queue, so it hands
over the operation that closes it too, and the backend this package
binds from `QUEUE_CONNECTION` has its `dispose()` registered on the
application scope when something first injects it. A constructor called
directly receives a transport you already own and closes none of it:
`dispose()` is then a no-op. Build a backend yourself and the disposal
is yours to register:

```php
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueSql\SqlQueueFactory;

$queue = SqlQueueFactory::fromConfig($config);

$app->instance(QueueInterface::class, $queue);
$app->onDispose($queue->dispose(...));
```

`dispose()` is idempotent and safe before the queue's first I/O.

## Clearing is a separate capability

`QueueInterface` carries only what every backend does identically.
Discarding the jobs waiting on a queue is not one of those, so it lives
on `Kinetis\Queue\ClearableQueueInterface`, which extends
`QueueInterface` and is declared by `kinetis/queue-redis`,
`kinetis/queue-sql`, `kinetis/queue-rabbitmq`, and `SyncQueue`. One
instance still pushes, pops and reports size. `kinetis/queue-sqs` does not
declare it: Amazon SQS has no operation that meets the contract, so an
SQS queue is emptied through infrastructure instead — see that package's
own README.

```php
use Kinetis\Queue\ClearableQueueInterface;

final readonly class ImportsMaintenance
{
    public function __construct(
        private ClearableQueueInterface $queue,
    ) {}

    public function discardPendingImports(): int
    {
        return $this->queue->clear('imports');
    }
}
```

Take `ClearableQueueInterface` where you clear, `QueueInterface`
everywhere else. Resolving the capability against a backend that lacks
it raises `Kinetis\Queue\Exception\QueueNotClearableException`, naming
that backend. `queue:clear` holds the base contract, so it checks at
runtime instead and exits 1 with the same wording, without touching a
queue; it also validates every name in `--queue` as one list before
clearing anything, so a mistyped or repeated name leaves the queues
ahead of it in the list untouched.

`clear()` returns what that call removed — a queue accepts pushes
throughout, so it is never expected to match a `size()` taken alongside
it. Reserved jobs are never removed and never counted.

## A settlement is per delivery, not per job

`QueuedJob::$handle` is a delivery receipt: it identifies one exact
delivery, so the same job reaching a worker again after a retry or an
expired reservation carries a different handle. A backend that can tell
a live reservation from a finished delivery answers a settlement for the
latter with `Kinetis\Queue\Exception\StaleJobHandleException` rather
than settling somebody else's delivery.

`queue:work` catches that on `ack()`, `release()` and `fail()` alike: the
loop keeps running, none of the three outcome events fires — no durable
transition happened — and `JobSettlementLost` plus a warning-level log
line report the loss. Every other exception from a settlement propagates
and stops the worker. Full detail:
[kinetis.dev/docs/queue.html](https://kinetis.dev/docs/queue.html).

## A running job keeps its reservation

A backend that holds a delivery for a finite window it can push forward
declares `Kinetis\Queue\RenewableQueueInterface`, which adds
`visibilityTimeoutSeconds()` and `renew(QueuedJob $job)`:
`kinetis/queue-redis`, `kinetis/queue-sql` and `kinetis/queue-sqs`.
`kinetis/queue-rabbitmq` needs neither — its channel holds the
unacknowledged delivery for as long as the connection lives — and
neither does `SyncQueue`.

`queue:work` resolves the capability once and renews the running job's
reservation at half that window, so a job that legitimately takes longer
than the window keeps its delivery instead of being handed to a second
worker. There is nothing to configure and nothing for a job to call: a
job never sees its own receipt.

Delivery is still at least once. Nothing renews once the worker dies —
which is the point — and a handler that never yields to the event loop
cannot be renewed either, because nothing in the worker can interrupt
running PHP. A renewal the backend refuses neither fails nor settles the
job: the worker keeps trying for the rest of the job and, after it has
attempted the job's own settlement, logs one `error` with the failure
count and the last exception. A renewal the worker cannot wait out
before settling is the exception — it stops the worker with the delivery
unsettled, rather than settling one a suspended renewal can still reach.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config` — by
`kinetis queue:work` and by this package's bootstrap, which binds
`QueueInterface` to the selected backend with no application wiring.
Each backend's own connection details are documented in that backend's
own package ([`kinetis/queue-redis`](https://github.com/kinetis-dev/queue-redis), [`kinetis/queue-sql`](https://github.com/kinetis-dev/queue-sql),
[`kinetis/queue-sqs`](https://github.com/kinetis-dev/queue-sqs), [`kinetis/queue-rabbitmq`](https://github.com/kinetis-dev/queue-rabbitmq)) — this package installs
none of them, so picking `QUEUE_CONNECTION=redis` (say) without also
`composer require kinetis/queue-redis` fails clearly, naming the
package to install.

| Key | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | *(required)* | `redis`, `sql`, `sqs`, or `rabbitmq` — each needs its own package installed. |
| `QUEUE_CONNECTION_NAME` | `default` | Which named connection block the backend uses. |
| `QUEUE_MAX_ATTEMPTS` | `0` | Worker-level default attempts cap (`0` = no retries); a job's own `push(maxAttempts: ...)` wins. |
| `QUEUE_RETRY_BASE_DELAY_SECONDS` | `5` | Seconds the first retry waits, doubling per attempt up to a fixed 15-minute ceiling. `0`–`900`; `0` retries immediately. The backend holds the job, so the worker never sleeps. |
| `QUEUE_POLL_TIMEOUT` | `5` | Seconds `queue:work` waits per poll; must be at least 1, so the worker can periodically check for a shutdown signal. |
| `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | `300` | Read by `kinetis/queue-redis`, `kinetis/queue-sql` and `kinetis/queue-sqs`: how long a crashed worker's job stays reserved. The worker renews a running job's reservation at half this, so it sizes crash recovery rather than job duration. |

Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/queue
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework). Full documentation:
[kinetis.dev/docs/queue.html](https://kinetis.dev/docs/queue.html).

## License

MIT — see [LICENSE](LICENSE).
