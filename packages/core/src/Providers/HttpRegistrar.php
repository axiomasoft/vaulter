<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Fruitcake\Cors\CorsService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Http\Middleware\ExposeVaulterCorsHeaders;
use Vaulter\Http\Problems\ErrorsReference;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Http\Problems\ProblemSources;

/**
 * The kernel's HTTP diagnostics, applied whatever the module graph: the problem catalogue and renderer and the
 * priority of the response middleware.
 */
#[Internal]
final class HttpRegistrar
{
    public function register(Application $app): void
    {
        $app->scoped(ProblemCatalog::class, static fn (Application $app): ProblemCatalog => new ProblemCatalog(
            static fn (): array => ProblemSources::active($app),
            $app->make(Translator::class),
        ));
        $app->scoped(ProblemRenderer::class);
        $app->extend(CorsService::class, static fn (CorsService $cors, Application $app): CorsService => $cors::class === CorsService::class
            ? new ExposeVaulterCorsHeaders($app, $app->make(Repository::class))
            : $cors);
        // The reference docs list the codes of every offered module, an opt-in one included.
        $app->bind(ErrorsReference::class, static fn (Application $app): ErrorsReference => new ErrorsReference(new ProblemCatalog(
            static fn (): array => ProblemSources::offered($app),
            $app->make(Translator::class),
        )));
    }

    public function boot(Application $app): void
    {
        $this->registerResourceMiddlewarePriority($app->make(Kernel::class));
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
            // the API version and the cache policy. The module check goes right after them, ahead of
            // auth and throttle: a route of an inactive module answers 404 (503 on an invalid graph),
            // not 401 or 429.
            foreach ([EnsureModuleActive::class, ApplyResponseCachePolicy::class, AddApiVersionHeader::class, AssignCorrelationId::class] as $middleware) {
                $kernel->prependToMiddlewarePriority($middleware);
            }
        }
    }
}
