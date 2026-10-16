<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Kernel;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;

/**
 * Reclaim the webhook delivery history past its retention window: TERMINAL deliveries,
 * status 'delivered' or 'dead' (DLQ), whose updated_at (the terminalisation time) is
 * older than the window. A 'pending'/'failed' row is in-flight / retryable — the
 * due-retry sweep still owns it — and is NEVER touched. The outbox itself has its own
 * retention, vaulter:outbox:prune (D29).
 *
 * The window comes from configuration key vaulter.webhooks.retention_days (default 30, floored
 * at 0) unless --days overrides it. Deleted rows cannot be restored or replayed, so
 * --dry-run reports the exact counts it would remove without touching a row.
 * Schedulable.
 *
 * While the webhooks module is inactive the command no-ops with a notice.
 */
#[Internal]
final class PruneWebhooksCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:webhooks:prune
        {--days= : Override the retention window in days (config vaulter.webhooks.retention_days)}
        {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Delete terminal webhook deliveries past the retention window.';

    public function handle(WebhookDeliveryStoreRepository $deliveries, MetricsRecorder $metrics): int
    {
        if (!app(Kernel::class)->isActive('webhooks')) {
            $this->components->info('Webhooks module is inactive — nothing to prune.');

            return self::SUCCESS;
        }

        $retentionDays = $this->retentionDays();
        $threshold = Carbon::now()->subDays($retentionDays);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            return $this->reportDryRun(
                deliveries: $deliveries,
                threshold: $threshold,
                retentionDays: $retentionDays,
            );
        }

        $prunedDeliveries = $metrics->timing(
            name: 'vaulter_prune_duration_seconds',
            operation: static fn (): int => $deliveries->pruneTerminalBefore(threshold: $threshold),
        );

        $metrics->counter(
            name: 'vaulter_prune_rows_total',
            amount: $prunedDeliveries,
        );

        $this->components->info(sprintf(
            'Pruned %d terminal deliver%s older than %s (retention %d day%s).',
            $prunedDeliveries,
            $prunedDeliveries === 1 ? 'y' : 'ies',
            $threshold->toDateTimeString(),
            $retentionDays,
            $retentionDays === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    private function reportDryRun(
        WebhookDeliveryStoreRepository $deliveries,
        Carbon $threshold,
        int $retentionDays,
    ): int {
        $deliveryCount = $deliveries->countTerminalBefore(threshold: $threshold);

        $this->components->info(sprintf(
            'Would prune %d terminal deliver%s older than %s (retention %d day%s).',
            $deliveryCount,
            $deliveryCount === 1 ? 'y' : 'ies',
            $threshold->toDateTimeString(),
            $retentionDays,
            $retentionDays === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * The retention window: the --days override when a non-negative integer, else
     * the configured default. Floored at 0 (prune as soon as a row settles).
     */
    private function retentionDays(): int
    {
        $override = $this->option('days');

        if (is_string($override) && is_numeric($override)) {
            return max((int) $override, 0);
        }

        return app(WebhooksConfig::class)->retentionDays();
    }
}
