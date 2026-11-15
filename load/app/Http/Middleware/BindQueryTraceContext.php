<?php

declare(strict_types=1);

namespace Vaulter\Load\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Load\Observability\QueryTraceRecorder;

/**
 * Binds HTTP operation context for {@see QueryTraceRecorder}.
 */
final readonly class BindQueryTraceContext
{
    public function __construct(
        private QueryTraceRecorder $recorder,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (filter_var(env('LOAD_DB_TRACE', false), FILTER_VALIDATE_BOOL)) {
            $this->recorder->bindHttpRequest($request);
        }

        return $next($request);
    }

    public function terminate(): void
    {
        if (filter_var(env('LOAD_DB_TRACE', false), FILTER_VALIDATE_BOOL)) {
            $this->recorder->flush();
        }
    }
}
