<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Queue\Exception\UnresolvableJobParameterException;
use Kinetis\Queue\JobInvoker;
use Kinetis\Queue\Tests\Fixtures\Recorder;
use Kinetis\Queue\Tests\Fixtures\RecordingJob;
use Kinetis\Queue\Tests\Fixtures\UnresolvableParameterJob;
use PHPUnit\Framework\TestCase;

final class JobInvokerTest extends TestCase
{
    public function test_invoke_resolves_handles_class_typed_parameters_through_the_container(): void
    {
        $app = new AppScope();
        $app->instance(Recorder::class, new Recorder());
        $app->boot();

        JobInvoker::invoke(new RecordingJob('hello'), $app);

        self::assertSame(['hello'], $app->get(Recorder::class)->messages);
    }

    public function test_invoke_throws_when_a_handle_parameter_is_not_class_typed(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(UnresolvableJobParameterException::class);

        JobInvoker::invoke(new UnresolvableParameterJob(), $app);
    }
}
