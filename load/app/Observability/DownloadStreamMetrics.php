<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Illuminate\Support\Facades\Http;
use Throwable;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;

/**
 * Host collector for `vaulter_download_stream_active` via php-fpm status.
 */
final class DownloadStreamMetrics
{
    private const string STATUS_URL = 'http://127.0.0.1:8081/fpm-status?json&full';

    public function __construct(
        private readonly RoutesConfig $routesConfig,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function observeActiveStreams(): void
    {
        $active = 0;

        try {
            $response = Http::timeout(seconds: 1)->get(self::STATUS_URL);

            if (!$response->successful()) {
                $this->emitGauge(value: 0);

                return;
            }

            $payload = $response->json();

            if (!is_array($payload)) {
                $this->emitGauge(value: 0);

                return;
            }

            foreach ($payload['processes'] ?? [] as $process) {
                if (!is_array($process)) {
                    continue;
                }

                if (($process['state'] ?? '') !== 'Running') {
                    continue;
                }

                $uri = (string) ($process['request uri'] ?? $process['request_uri'] ?? '');

                if ($this->isDownloadRequestUri($uri)) {
                    $active++;
                }
            }

            $ceiling = $this->maxChildrenCeiling($payload);
            $this->emitGauge(value: $ceiling > 0 ? min($active, $ceiling) : $active);
        } catch (Throwable) {
            $this->emitGauge(value: 0);
        }
    }

    private function emitGauge(int $value): void
    {
        $this->metrics->gauge(
            name: 'vaulter_download_stream_active',
            value: $value,
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private function maxChildrenCeiling(array $payload): int
    {
        foreach (['max children', 'total processes'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return 0;
    }

    private function isDownloadRequestUri(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = $uri;
        }

        $normalized = trim($path, '/');

        if (str_starts_with($normalized, 'index.php/')) {
            $normalized = substr($normalized, strlen('index.php/'));
        }

        $prefix = trim($this->routesConfig->prefix(), '/');

        if ($prefix !== '' && str_starts_with($normalized, $prefix.'/')) {
            $normalized = substr($normalized, strlen($prefix) + 1);
        }

        return app(VaulterHttpOperationMap::class)->resolve(method: 'GET', pathAfterPrefix: $normalized) === 'download';
    }
}
