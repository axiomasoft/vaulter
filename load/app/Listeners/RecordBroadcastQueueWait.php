<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\Events\JobProcessing;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * Records `vaulter_broadcast_queue_wait_seconds` from enqueue to job start.
 */
final class RecordBroadcastQueueWait
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(JobProcessing $event): void
    {
        if (!$this->isBroadcastJob($event->job)) {
            return;
        }

        $payload = $event->job->payload();
        $pushedAt = $payload['pushedAt'] ?? null;

        if (!is_numeric($pushedAt)) {
            return;
        }

        $waitSeconds = max(0, microtime(true) - (float) $pushedAt);

        $this->metrics->histogram(
            name: 'vaulter_broadcast_queue_wait_seconds',
            value: $waitSeconds,
        );
    }

    private function isBroadcastJob(object $job): bool
    {
        $name = method_exists($job, 'resolveName') ? $job->resolveName() : '';

        return is_string($name) && str_contains($name, class_basename(BroadcastEvent::class));
    }
}
