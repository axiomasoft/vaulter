<?php

declare(strict_types=1);

namespace Vaulter\Testing\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Jobs\JobRegistry;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PreparedJob;
use Vaulter\Jobs\PublicationContext;

/** @internal Explicit fake-only host binding for strict legacy resolver unit tests; no runtime/worker guarantee. */
final readonly class FixtureJobPublisher implements JobPublisher
{
    public function prepare(JobRequest $request, ?PublicationContext $origin = null): PreparedJob
    {
        $tenant = app(TenantResolver::class)->current();
        if ($tenant === null) {
            throw new \InvalidArgumentException('Fixture requires a current tenant.');
        }

        return new PreparedJob($request, $origin ?? new PublicationContext('fixture:tenant', 1, ['tenant' => (string) $tenant]));
    }

    public function publish(PreparedJob $prepared): void
    {
        app(Dispatcher::class)->dispatch(app(JobRegistry::class)->make($prepared));
    }
}
