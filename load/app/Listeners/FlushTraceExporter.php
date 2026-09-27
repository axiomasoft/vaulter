<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;

/**
 * Bounded forceFlush on every request, job and command terminal hook (P8.11 / §20.5).
 * Flush failure stays inside the recorder and does not change the business result.
 */
final class FlushTraceExporter
{
    public function __construct(
        private readonly TraceRecorder $recorder,
    ) {}

    public function handle(RequestHandled|JobProcessed|JobFailed|JobExceptionOccurred|CommandFinished $event): void
    {
        if (!$this->recorder instanceof OpenTelemetryTraceRecorder) {
            return;
        }

        $this->recorder->forceFlush();
    }
}
