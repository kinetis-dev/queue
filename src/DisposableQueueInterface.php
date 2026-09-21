<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * Releasing the transport a queue opened for itself — a capability a
 * backend has only when it created a connection nobody else owns.
 *
 * A queue backend lives for the whole worker, so its connection is
 * application-scoped and has to be closed once when that worker ends.
 * `PackageBootstrap` registers dispose() on the `AppScope` for the
 * backend `QUEUE_CONNECTION` made it build, and for no other instance:
 * a queue an application binds itself belongs to that application.
 *
 * **Ownership travels with construction, not with the type.** A
 * backend's factory opens the client or link it hands the queue, so it
 * also hands over the operation that closes it. A constructor called
 * directly receives a client or link the caller already owns and closes
 * none of it: dispose() is then a no-op, and closing that transport
 * stays with whoever opened it. A caller that wants the queue to own
 * what it passed says so by supplying the closing operation itself —
 * see each backend's constructor.
 *
 * dispose() is idempotent and safe before the queue's first I/O: a
 * worker that never popped anything still disposes cleanly, and a
 * second call does nothing. The queue is finished afterwards; nothing
 * reuses one it has disposed.
 *
 * Extends QueueInterface rather than sitting beside it: anything
 * holding this holds a whole queue. Kinetis\QueueSqs\SqsQueue does not
 * declare it — its transport is an HTTP client with no queue-owned
 * connection to close.
 */
interface DisposableQueueInterface extends QueueInterface
{
    public function dispose(): void;
}
