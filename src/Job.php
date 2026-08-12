<?php

declare(strict_types=1);

namespace Kinetis\Queue;

/**
 * A marker interface only — deliberately no `handle()` method declared
 * here, unlike Kinetis\Migrations\Migration's fixed-signature up()/down().
 * A migration's up() always takes exactly one dependency
 * (MysqlLink|PostgresLink), so a real interface method works; a job's
 * handle() needs arbitrary, job-specific dependencies (a Mailer for one
 * job, a Logger and a repository for another), the same shape a
 * controller action or an #[McpTool] method needs, neither of which is
 * expressible as one fixed interface signature either. QueueWorker
 * resolves handle()'s own parameter list through the container and
 * invokes it by name, the same reflect-then-dynamic-call shape
 * Dispatcher/McpDispatcher already use for exactly this reason.
 *
 * Every constructor parameter must correspond to a same-named property
 * JobSerializer can read back — see that class's docblock — since a job
 * is constructed on one process and reconstructed on a different one
 * later, with only its constructor's own data surviving the round trip.
 */
interface Job
{
}
