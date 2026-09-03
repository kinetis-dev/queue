<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

/**
 * Deliberately does not implement Kinetis\Queue\Job — for proving
 * JobSerializer::deserializeJob() rejects reconstructing this as a job,
 * even though deserialize() (the general path) reconstructs it fine.
 */
final readonly class NotAJobObject
{
    public function __construct(
        public string $value,
    ) {}
}
