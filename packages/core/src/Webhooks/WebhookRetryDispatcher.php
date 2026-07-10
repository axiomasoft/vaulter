<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
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
    /**
     * Claim every due retry and dispatch its delivery job exactly once. Returns how
     * many were re-dispatched.
     */
    public function redispatchDue(): int
    {
        $deliveries = $this->claimDue();

        foreach ($deliveries as $delivery) {
            Bus::dispatch(new DeliverWebhook(deliveryId: Identity::stringify($delivery->getKey())));
        }

        return $deliveries->count();
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

        return DB::transaction(function () use ($deliveryClass): Collection {
            /** @var Collection<int, WebhookDelivery> $deliveries */
            $deliveries = $deliveryClass::query()
                ->dueForRetry()
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
