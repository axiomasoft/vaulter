<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The SINGLE owner of webhook-delivery re-dispatch (§2.11 / F-P1.7-05). Both
 * vaulter:relay-outbox and vaulter:retry-webhooks route their due-retry sweep through
 * here, so two ticks (or the two commands overlapping) can never fan the same failed
 * delivery out twice.
 *
 * A due batch is claimed under a row lock inside a transaction and its next_retry_at is
 * re-armed forward by a short claim window BEFORE dispatch — a visibility timeout: a
 * concurrent or immediately-following sweep no longer sees the row as due, and where
 * the row lock degrades (SQLite) the committed re-arm still guarantees a single owner
 * per claim. If the queued job is lost, the window lapses and the row becomes due
 * again (self-healing) — DeliverWebhook overwrites next_retry_at with the real backoff
 * on the next failure, or clears it on success. A new intermediate status was avoided
 * on purpose: it would strand a delivery (neither due nor terminal) if the job were
 * lost mid-flight.
 */
final class WebhookRetryDispatcher
{
    private const int MAX_CLAIM_ROWS = 1000;

    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly TraceRecorder $tracer,
        private readonly VaulterDatabase $database,
    ) {}

    /**
     * Claim at most 1000 due retries and dispatch each delivery job once. Returns
     * how many were re-dispatched; later ticks drain the remaining due rows.
     */
    public function redispatchDue(): int
    {
        return $this->tracer->span(
            name: TracingSpanNames::WEBHOOK_RETRY,
            operation: fn (): int => $this->metrics->timing(
                name: 'vaulter_webhook_retry_tick_seconds',
                operation: function (): int {
                    $deliveries = $this->claimDue();

                    $this->metrics->histogram(
                        name: 'vaulter_webhook_retry_lockset_rows',
                        value: $deliveries->count(),
                    );

                    foreach ($deliveries as $delivery) {
                        Bus::dispatch(new DeliverWebhook(deliveryId: Identity::stringify($delivery->getKey())));
                    }

                    return $deliveries->count();
                },
            ),
            attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'action'),
        );
    }

    /**
     * Atomically read the due retries under a row lock and re-arm their next_retry_at
     * forward by the claim window, so the same row is claimed once per call.
     *
     * @return Collection<int, WebhookDelivery>
     */
    private function claimDue(): Collection
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = Config::webhookDeliveryModel();

        return $this->database->transaction(function () use ($deliveryClass): Collection {
            $query = $deliveryClass::query()->dueForRetry();

            /** @var Collection<int, WebhookDelivery> $deliveries */
            $deliveries = $query
                ->orderBy('next_retry_at')
                ->orderBy($query->getModel()->getQualifiedKeyName())
                ->limit(self::MAX_CLAIM_ROWS)
                ->lockForUpdate()
                ->get();

            $claimUntil = now()->addSeconds(Config::webhookRetryClaimSeconds());

            $deliveries->each(static function (WebhookDelivery $delivery) use ($claimUntil): void {
                $delivery->forceFill(['next_retry_at' => $claimUntil])->save();
            });

            return $deliveries;
        });
    }
}
