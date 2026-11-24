<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Tenancy\RestoreTenantContext;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Context::forget(RestoreTenantContext::CONTEXT_KEY);
});

it('parses tenancy.rls option and rejects non-boolean values', function (): void {
    expect(TenancyOptions::fromArray([])->rls)->toBeFalse()
        ->and(TenancyOptions::fromArray(['enabled' => true, 'rls' => true])->rls)->toBeTrue()
        ->and(fn () => TenancyOptions::fromArray(['rls' => 'yes']))->toThrow(InvalidConfiguration::class);
});

it('prevents vaulter.tenant_id Context leak across consecutive Octane HTTP requests', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    $resolver = new class implements TenantResolver
    {
        public int|string|null $tenant = null;

        public function current(): int|string|null
        {
            return $this->tenant;
        }
    };
    $this->app->instance(TenantResolver::class, $resolver);

    Route::middleware('vaulter.tenant')->get('/_test/octane-tenant', function (Request $request) {
        return response()->json(['context' => RestoreTenantContext::current()]);
    });

    $resolver->tenant = 'tenant-alpha';
    $this->getJson('/_test/octane-tenant')
        ->assertOk()
        ->assertJson(['context' => 'tenant-alpha']);
    expect(Context::get(RestoreTenantContext::CONTEXT_KEY))->toBeNull();

    // Second request in the same worker/application with no tenant must not inherit tenant-alpha.
    $resolver->tenant = null;
    $this->getJson('/_test/octane-tenant')
        ->assertOk()
        ->assertJson(['context' => null]);
    expect(Context::get(RestoreTenantContext::CONTEXT_KEY))->toBeNull();
});

it('propagates and cleans up vaulter.tenant_id across sequential queued jobs without leaking', function (): void {
    $middleware = new RestoreTenantContext;
    $baseResolver = new class implements TenantResolver
    {
        public function current(): int|string|null
        {
            return null;
        }
    };
    $this->app->instance(TenantResolver::class, $baseResolver);

    // Job 1 hydrated with Context from Tenant A.
    Context::add(RestoreTenantContext::CONTEXT_KEY, 'tenant-job-1');
    $job1 = new class {};
    $seen1 = $middleware->handle($job1, fn (): array => [
        'ctx' => RestoreTenantContext::current(),
        'resolved' => app(TenantResolver::class)->current(),
    ]);

    expect($seen1)->toBe(['ctx' => 'tenant-job-1', 'resolved' => 'tenant-job-1'])
        ->and(Context::get(RestoreTenantContext::CONTEXT_KEY))->toBeNull()
        ->and(app(TenantResolver::class)->current())->toBeNull();

    // Job 2 with no tenant must see null.
    $job2 = new class {};
    $seen2 = $middleware->handle($job2, fn (): array => [
        'ctx' => RestoreTenantContext::current(),
        'resolved' => app(TenantResolver::class)->current(),
    ]);

    expect($seen2)->toBe(['ctx' => null, 'resolved' => null]);

    // Mismatched job property vs hydrated Context fails closed.
    Context::add(RestoreTenantContext::CONTEXT_KEY, 'tenant-a');
    $mismatchJob = new class
    {
        public string $tenantId = 'tenant-b';
    };
    expect(fn () => $middleware->handle($mismatchJob, fn () => null))->toThrow(InvalidArgumentException::class);
});
