<?php

declare(strict_types=1);

namespace Kinetis\Queue\Attributes;

use Attribute;

/**
 * Marks a job constructor parameter whose value must never reach a log.
 *
 * QueueWorker records a job's arguments when it gives up on one, and a job
 * routinely carries a token, an email address, or customer data that has
 * no business in a log aggregator. A marked parameter is written as
 * JobSerializer::REDACTED there instead, while every unmarked one is
 * logged as-is — so the entry stays useful for tracing what failed.
 *
 * The value still travels to the queue backend in full, because the worker
 * needs it to run the job. This governs what is logged, not what is
 * stored.
 *
 * Marking a parameter holding an array or an object redacts that value
 * whole; there is no per-element redaction within one.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Sensitive {}
