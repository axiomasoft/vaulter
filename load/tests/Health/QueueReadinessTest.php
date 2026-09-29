<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Vaulter\Load\Health\QueueReadiness;

it('does not treat a stale nonce cache entry as readiness', function (): void {
    config([
        'queue.default' => 'redis',
        'queue.connections.redis' => [
            'driver' => 'sync',
            'queue' => 'default',
        ],
        'load.health.queue_probe_timeout_seconds' => 0.2,
        'load.health.queue_poll_interval_us' => 10_000,
        'cache.default' => 'array',
    ]);

    Cache::put(QueueReadiness::cacheKey('stale-nonce'), 1_700_000_000_000, 60);

    Bus::fake();

    expect(app(QueueReadiness::class)->probe())->toBeFalse();
    expect(Cache::has(QueueReadiness::cacheKey('stale-nonce')))->toBeTrue();
});
