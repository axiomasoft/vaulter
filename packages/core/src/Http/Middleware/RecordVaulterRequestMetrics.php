<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Support\LoadTier;

/**
 * Records `vaulter_http_request_duration_seconds` for mapped Vaulter API routes (P8.3 / §9.1).
 */
final readonly class RecordVaulterRequestMetrics
{
    public function __construct(
        private readonly RoutesConfig $routesConfig,
        private MetricsRecorder $metrics,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $operation = $this->operationFor($request);
        $outcome = 'exception';

        try {
            $response = $next($request);
            $outcome = $this->outcomeFor($response->getStatusCode());

            return $response;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            if ($operation !== null) {
                $this->metrics->histogram(
                    name: 'vaulter_http_request_duration_seconds',
                    value: (hrtime(true) - $started) / 1_000_000_000,
                    labels: [
                        'operation' => $operation,
                        'tier' => LoadTier::label(),
                        'outcome' => $outcome,
                    ],
                );
            }
        }
    }

    private function operationFor(Request $request): ?string
    {
        $prefix = trim($this->routesConfig->prefix(), '/');
        $path = trim($request->path(), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        return VaulterHttpOperationMap::resolve(method: $request->method(), pathAfterPrefix: $path);
    }

    private function outcomeFor(int $status): string
    {
        if ($status >= 500) {
            return 'server_error';
        }

        if ($status >= 400) {
            return 'client_error';
        }

        return 'success';
    }
}
