<?php

declare(strict_types=1);

namespace Vaulter\Load\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Vaulter\Load\Health\QueueReadiness;

final class LoadQueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $nonce,
        public readonly int $issuedAtMs,
    ) {}

    public function handle(): void
    {
        $timeoutSeconds = (float) config('load.health.queue_probe_timeout_seconds', 3);

        Cache::put(
            QueueReadiness::cacheKey($this->nonce),
            $this->issuedAtMs,
            $timeoutSeconds,
        );
    }
}
