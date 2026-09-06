<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use DateTimeImmutable;

/**
 * A DateTimeImmutable subclass, which JobSerializer rejects because a
 * subclass's own state has no representation on the wire.
 */
final class CustomDate extends DateTimeImmutable {}
