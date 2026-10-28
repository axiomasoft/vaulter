<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\PendingDispatch;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;

/** @internal Default for disabled tenancy; strict hosts must explicitly supply a publisher. */
final readonly class FrameworkJobPublisher implements JobPublisher
{
    public function __construct(private Container $container, private JobRegistry $registry, private TenancyConfig $tenancy) {}

    public function prepare(JobRequest $request, ?PublicationContext $origin = null): PreparedJob
    {
        if ($this->tenancy->enabled() || $origin !== null) {
            throw new TenancyMisconfiguredException('Strict job publication requires an explicit host JobPublisher.');
        }

        return new PreparedJob($request);
    }

    public function publish(PreparedJob $job): void
    {
        $this->prepare($job->request, $job->context);
        $instance = $this->registry->make($job);
        if ($job->request->queue !== null && method_exists($instance, 'onQueue')) {
            $instance->onQueue($job->request->queue);
        }
        // Preserve Laravel's unique-until-processing contract (Dispatcher::dispatch alone omits it).
        if ($instance instanceof ShouldBeUnique) {
            $pending = new PendingDispatch($instance);
            unset($pending);

            return;
        }
        $this->container->make(Dispatcher::class)->dispatch($instance);
    }
}
