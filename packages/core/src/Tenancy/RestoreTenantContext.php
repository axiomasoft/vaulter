<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Closure;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Support\Identity;

/**
 * Propagates and cleans up `vaulter.tenant_id` across Octane HTTP requests and queued jobs
 * using Laravel's scoped Context repository so tenant state never leaks between executions.
 */
#[Api(since: '1.0')]
final readonly class RestoreTenantContext
{
    public const string CONTEXT_KEY = 'vaulter.tenant_id';

    public function __construct(private readonly ?Container $container = null) {}

    public static function current(): ?string
    {
        $raw = Context::get(self::CONTEXT_KEY);

        if (is_int($raw)) {
            return (string) $raw;
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(mixed): TReturn $next
     * @return TReturn
     */
    public function handle(mixed $passable, Closure $next): mixed
    {
        if ($passable instanceof Request) {
            return $this->handleRequest($passable, $next);
        }

        if (is_object($passable)) {
            return $this->handleJob($passable, $next);
        }

        return $next($passable);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(Request): TReturn $next
     * @return TReturn
     */
    private function handleRequest(Request $request, Closure $next): mixed
    {
        $container = $this->resolveContainer();
        $tenancy = $container->make(TenancyOptions::class);
        $bypass = $container->make(TenancyBypass::class);

        $tenant = null;
        if (!$bypass->active() && ($tenancy->enabled || $tenancy->resolver !== null || $container->resolved(TenantResolver::class))) {
            $resolved = $container->make(TenantResolver::class)->current();
            $tenant = $resolved === null ? null : Identity::stringify($resolved);
        }

        if ($tenant !== null && $tenant !== '') {
            Context::add(self::CONTEXT_KEY, $tenant);
        } else {
            Context::forget(self::CONTEXT_KEY);
        }

        try {
            return $next($request);
        } finally {
            Context::forget(self::CONTEXT_KEY);
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(object): TReturn $next
     * @return TReturn
     */
    private function handleJob(object $job, Closure $next): mixed
    {
        $container = $this->resolveContainer();
        $fromContext = self::current();
        $fromJob = $this->jobTenantId($job);

        if ($fromContext !== null && $fromJob !== null && $fromContext !== $fromJob) {
            throw new InvalidArgumentException('Queued job tenant_id does not match hydrated Context.');
        }

        $tenant = $fromContext ?? $fromJob;
        $hadResolved = $container->resolved(TenantResolver::class);
        $previousResolver = $hadResolved ? $container->make(TenantResolver::class) : null;

        if ($tenant !== null && $tenant !== '') {
            Context::add(self::CONTEXT_KEY, $tenant);
            $container->instance(TenantResolver::class, new class($tenant) implements TenantResolver
            {
                public function __construct(private readonly string $tenant) {}

                public function current(): string
                {
                    return $this->tenant;
                }
            });
        } else {
            Context::forget(self::CONTEXT_KEY);
        }

        try {
            return $next($job);
        } finally {
            Context::forget(self::CONTEXT_KEY);
            if ($tenant !== null && $tenant !== '') {
                if ($previousResolver instanceof TenantResolver) {
                    $container->instance(TenantResolver::class, $previousResolver);
                } else {
                    $container->forgetInstance(TenantResolver::class);
                }
            }
        }
    }

    private function jobTenantId(object $job): ?string
    {
        if (!property_exists($job, 'tenantId')) {
            return null;
        }

        $value = $job->tenantId;
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveContainer(): Container
    {
        return $this->container ?? ContainerInstance::getInstance();
    }
}
