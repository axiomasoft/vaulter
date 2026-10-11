<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Support\Identity;

final class HttpRegistrar
{
    public function register(Application $app): void
    {
        $app->singleton(ProblemCatalog::class, static fn (Application $app): ProblemCatalog => new ProblemCatalog(
            static fn (): iterable => $app->tagged(ProblemCatalog::TAG),
            $app->make(Translator::class),
        ));
        $app->singleton(ProblemRenderer::class);
        $app->singleton(CoreProblemCodes::class);
        $app->tag(CoreProblemCodes::class, ProblemCatalog::TAG);
    }

    public function boot(Application $app): void
    {
        $this->registerResourceMiddlewarePriority($app->make(Kernel::class));
        $this->registerRateLimiters();
        $this->registerProblemRenderer($app);
    }

    /**
     * Only a handler with renderables (the Foundation one) takes the renderer; a host's
     * own handler keeps the answers it already had.
     */
    private function registerProblemRenderer(Application $app): void
    {
        $this->attachProblemRenderer($app->make(ExceptionHandler::class), $app);
    }

    private function attachProblemRenderer(object $handler, Application $app): void
    {
        if (method_exists($handler, 'renderable')) {
            $handler->renderable(static fn (Throwable $e, Request $request): ?Response => $app->make(ProblemRenderer::class)->render($e, $request));
        }
    }

    private function registerResourceMiddlewarePriority(Kernel $kernel): void
    {
        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            // The router sorts prioritized middleware (throttle, auth, bindings) ahead of
            // the rest; these wrap them so a 401 or 429 still carries the request id,
            // the API version and the cache policy.
            foreach ([ApplyResponseCachePolicy::class, AddApiVersionHeader::class, AssignCorrelationId::class] as $middleware) {
                $kernel->prependToMiddlewarePriority($middleware);
            }
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
