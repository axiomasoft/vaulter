<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Repositories\Webhooks\OutboxStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;

/**
 * Reclaim the reliability channel's audit history past its retention window (master
 * plan). It deletes two kinds of FINISHED rows — they never change
 * again, so keeping them forever only grows the tables:
 *  - COMPLETED outbox rows: status 'completed' with fanned_out_at older than the
 *    window. A 'pending' row is still being fanned out — the relay owns it — and is
 *    NEVER touched, however old.
 *  - TERMINAL deliveries: status 'delivered' or 'dead' (DLQ) whose updated_at (the
 *    terminalisation time) is older than the window. A 'pending'/'failed' row is
 *    in-flight / retryable — the due-retry sweep still owns it — and is NEVER touched.
 *
 * The window comes from configuration key vaulter.webhooks.retention_days (default 30, floored
 * at 0) unless --days overrides it. Deleted rows cannot be restored or replayed, so
 * --dry-run reports the exact counts it would remove without touching a row.
 * Schedulable.
 *
 * The whole reliability surface is feature-gated: when the 'reliability' feature is off
 * the v_outbox / v_webhook_* tables may not exist, so the command no-ops with a notice
 * instead of querying a missing table.
 */
final class PruneWebhooksCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:webhooks:prune
        {--days= : Override the retention window in days (config vaulter.webhooks.retention_days)}
        {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Delete completed outbox rows and terminal webhook deliveries past the retention window.';

    public function handle(
        OutboxStoreRepository $outbox,
        WebhookDeliveryStoreRepository $deliveries,
        MetricsRecorder $metrics,
    ): int {
        if (!app(VaulterConfig::class)->featureEnabled('webhooks')) {
            $this->components->info('Reliability feature is off — nothing to prune.');

            return self::SUCCESS;
        }

        $retentionDays = $this->retentionDays();
        $threshold = Carbon::now()->subDays($retentionDays);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            return $this->reportDryRun(
                outbox: $outbox,
                deliveries: $deliveries,
                threshold: $threshold,
                retentionDays: $retentionDays,
            );
        }

        $prunedOutbox = 0;
        $prunedDeliveries = 0;

        $metrics->timing(
            name: 'vaulter_prune_duration_seconds',
            operation: function () use ($outbox, $deliveries, $threshold, &$prunedOutbox, &$prunedDeliveries): void {
                $prunedOutbox = $outbox->pruneCompletedBefore(threshold: $threshold);
                $prunedDeliveries = $deliveries->pruneTerminalBefore(threshold: $threshold);
            },
        );

        $metrics->counter(
            name: 'vaulter_prune_rows_total',
            amount: $prunedOutbox + $prunedDeliveries,
        );

        $this->components->info(sprintf(
            'Pruned %d completed outbox row%s and %d terminal deliver%s older than %s (retention %d day%s).',
            $prunedOutbox,
            $prunedOutbox === 1 ? '' : 's',
            $prunedDeliveries,
            $prunedDeliveries === 1 ? 'y' : 'ies',
            $threshold->toDateTimeString(),
            $retentionDays,
            $retentionDays === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    private function reportDryRun(
        OutboxStoreRepository $outbox,
        WebhookDeliveryStoreRepository $deliveries,
        Carbon $threshold,
        int $retentionDays,
    ): int {
        $outboxCount = $outbox->countCompletedBefore(threshold: $threshold);
        $deliveryCount = $deliveries->countTerminalBefore(threshold: $threshold);

        $this->components->info(sprintf(
            'Would prune %d completed outbox row%s and %d terminal deliver%s older than %s (retention %d day%s).',
            $outboxCount,
            $outboxCount === 1 ? '' : 's',
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
