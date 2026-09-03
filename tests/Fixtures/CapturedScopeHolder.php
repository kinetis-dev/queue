<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * A static handoff so a test can inspect the RequestScope a job ran in
 * after the fact — needed because every real QueueInterface
 * implementation, SyncQueue included, round-trips a pushed job through
 * JobSerializer::serialize()/deserializeJob(), reconstructing a
 * brand-new instance from plain constructor args rather than invoking
 * the same object reference a test holds onto before pushing it. An
 * instance property on the job itself can never observe that — only a
 * static holder both the original and the reconstructed instance can
 * reach survives the swap.
 */
final class CapturedScopeHolder
{
    public static ?RequestScope $scope = null;
}
