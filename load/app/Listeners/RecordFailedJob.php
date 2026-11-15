<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * Records `vaulter_failed_jobs_total` from Laravel's `JobFailed` event.
 */
final class RecordFailedJob
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(JobFailed $event): void
    {
        $this->metrics->counter(
            name: 'vaulter_failed_jobs_total',
            labels: ['outcome' => $this->classify($event)],
        );
    }

    private function classify(JobFailed $event): string
    {
        $message = strtolower($event->exception->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        return 'failure';
    }
}
