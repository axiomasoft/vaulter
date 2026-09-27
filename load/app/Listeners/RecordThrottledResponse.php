<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Http\Middleware\VaulterThrottledRouteMap;

/**
 * Records `vaulter_throttled_total` for throttled package routes (P8.4 / §5.2).
 */
final class RecordThrottledResponse
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(RequestHandled $event): void
    {
        if ($event->response->getStatusCode() !== 429) {
            return;
        }

        $route = VaulterThrottledRouteMap::resolve($event->request);

        if ($route === null) {
            return;
        }

        $this->metrics->counter(
            name: 'vaulter_throttled_total',
            labels: ['route' => $route],
        );
    }
}
