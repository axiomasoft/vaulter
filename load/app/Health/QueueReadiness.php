<?php

declare(strict_types=1);

namespace Vaulter\Load\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Vaulter\Load\Jobs\LoadQueueHeartbeat;

final class QueueReadiness
{
    public static function cacheKey(string $nonce): string
    {
        return 'vaulter:queue-ready:'.$nonce;
    }

    public function probe(): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        $connection = (string) config('load.health.queue_connection', 'redis');
        $queueName = (string) config('load.health.queue_name', 'load-health');

        $nonce = (string) Str::uuid();
        $issuedAtMs = (int) round(microtime(true) * 1000);

        $deadline = microtime(true) + (float) config('load.health.queue_probe_timeout_seconds', 3);
        $pollIntervalUs = (int) config('load.health.queue_poll_interval_us', 50_000);

        LoadQueueHeartbeat::dispatch($nonce, $issuedAtMs)
            ->onConnection($connection)
            ->onQueue($queueName);

        $cacheKey = self::cacheKey($nonce);

        while (microtime(true) < $deadline) {
            $value = Cache::pull($cacheKey);

            if ($value === null) {
                usleep($pollIntervalUs);

                continue;
            }

            return (int) $value === $issuedAtMs;
        }

        return false;
    }
}
