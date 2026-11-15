<?php

declare(strict_types=1);

namespace Vaulter\Load\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * Records `vaulter_command_peak_memory_bytes` after background artisan commands finish.
 */
final class RecordCommandPeakMemory
{
    /** @var list<string> Closed §9 vocabulary; unknown artisan names are dropped. */
    private const array COMMANDS = [
        'vaulter:uploads:sweep',
        'vaulter:blobs:gc',
        'vaulter:blobs:reconcile',
        'vaulter:trash:purge',
        'vaulter:webhooks:prune',
        'vaulter:webhooks:retry',
        'vaulter:webhooks:relay',
    ];

    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(CommandFinished $event): void
    {
        $command = $event->command ?? '';

        if (!in_array($command, self::COMMANDS, true)) {
            return;
        }

        $this->metrics->gauge(
            name: 'vaulter_command_peak_memory_bytes',
            value: memory_get_peak_usage(true),
            labels: ['command' => $command],
        );
    }
}
