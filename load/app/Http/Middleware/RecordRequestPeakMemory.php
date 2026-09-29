<?php

declare(strict_types=1);

namespace Vaulter\Load\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;
use Vaulter\Support\Config;
use Vaulter\Support\LoadTier;

/**
 * Records `vaulter_request_peak_memory_bytes` after the response is sent (P8.4 / §5.2).
 */
final readonly class RecordRequestPeakMemory
{
    public function __construct(
        private MetricsRecorder $metrics,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $operation = $this->operationFor($request);

        if ($operation === null) {
            return;
        }

        $this->metrics->gauge(
            name: 'vaulter_request_peak_memory_bytes',
            value: memory_get_peak_usage(true),
            labels: [
                'operation' => $operation,
                'tier' => LoadTier::label(),
            ],
        );
    }

    private function operationFor(Request $request): ?string
    {
        $prefix = trim(Config::routePrefix(), '/');
        $path = trim($request->path(), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        return VaulterHttpOperationMap::resolve(method: $request->method(), pathAfterPrefix: $path);
    }
}
