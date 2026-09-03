<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

enum Priority: string
{
    case High = 'high';
    case Low = 'low';
}
