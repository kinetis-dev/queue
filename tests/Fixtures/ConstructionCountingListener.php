<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Events\Listener;
use Kinetis\Events\ShouldQueue;

/**
 * A real, observable proof that this class is constructed exactly when
 * expected and never before — $constructions is a plain static counter
 * (an accepted NoStaticPropertiesRule exception for test fixtures),
 * reset by each test before use.
 */
final class ConstructionCountingListener implements ShouldQueue
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    #[Listener]
    public function onTestEvent(TestEvent $event): void
    {
    }
}
