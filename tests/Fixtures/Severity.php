<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

enum Severity: int
{
    case Info = 1;
    case Critical = 9;
}
