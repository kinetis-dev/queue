<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/queue</strong>
  <br>
  <strong>A backend-agnostic background job queue for Kinetis</strong>
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

Requires PHP 8.4+ and `kinetis/kinetis`. Full documentation:
[docs.kinetis.dev/queue.html](https://docs.kinetis.dev/queue.html).

## License

MIT — see [LICENSE](../../LICENSE).
