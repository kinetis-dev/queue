<?php

declare(strict_types=1);

namespace Kinetis\Queue\Exception;

use RuntimeException;

final class QueueUnavailableException extends RuntimeException
{
    public static function missingBackendPackage(string $backend, string $package): self
    {
        return new self("Cannot use QUEUE_CONNECTION=\"{$backend}\": install \"{$package}\" to enable it.");
    }
}
