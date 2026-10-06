<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Http\Middleware\ResolveVaulterRouteParameters;
use Vaulter\Support\Identity;

final class HttpRegistrar
{
    public function boot(Application $app): void
    {
        $this->registerResourceMiddlewarePriority($app->make(Kernel::class));
        $this->registerRateLimiters();
    }

    private function registerResourceMiddlewarePriority(Kernel $kernel): void
    {
        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ResolveVaulterRouteParameters::class);
        }
    }

    private function registerRateLimiters(): void
    {
        if (RateLimiter::limiter('vaulter-public') === null) {
            RateLimiter::for('vaulter-public', static fn (Request $request): Limit => Limit::perMinute(
                app(RoutesConfig::class)->publicRateLimit(),
            )->by($request->ip() ?? 'unknown'));
        }

        if (RateLimiter::limiter('vaulter-uploads') === null) {
            RateLimiter::for('vaulter-uploads', static function (Request $request): Limit {
                $user = $request->user();
                $key = $user instanceof Authorizable
                    ? 'actor:'.Identity::stringify($user->getAuthIdentifier())
                    : 'ip:'.($request->ip() ?? 'unknown');

                return Limit::perMinute(app(RoutesConfig::class)->uploadsRateLimit())->by($key);
            });
        }
    }
}
