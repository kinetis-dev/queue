<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/queue</strong>
  <br>
  <strong>A backend-agnostic background job queue for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/v/kinetis/queue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/dt/kinetis/queue" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/php-v/kinetis/queue" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue"><img src="https://img.shields.io/packagist/l/kinetis/queue" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Redis and SQL (MySQL/Postgres) backends included, behind one
`Kinetis\Queue\QueueInterface` — push a job from application code, a
separate `vendor/bin/queue` worker process pops and runs it. Named,
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
vendor/bin/queue work --queue=high,default
```

## Installation

```sh
composer require kinetis/queue
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/queue.html](https://docs.kinetis.dev/queue.html).

## License

MIT — see [LICENSE](../../LICENSE).
