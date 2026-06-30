<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The transactional-outbox RELAY (master plan §2.11 / D14 / M3b), ported from chatom
 * 1:1 — the reliable webhook fan-out half.
 *
 * process() drains due pending outbox rows (status='pending', available_at <= now),
 * expands each into one WebhookDelivery per subscribed ACTIVE endpoint, and dispatches
 * a DeliverWebhook job per delivery. The outbox row is then marked 'dispatched'.
 *
 * IDEMPOTENCY is row-state driven: only 'pending' rows are claimed, and the
 * read+flip-to-dispatched runs under a row lock inside a transaction, so a second
 * concurrent or repeated process() never fans the same event out twice. Combined with
 * the event_id UNIQUE staging guard, the whole pipeline is exactly-once into
 * deliveries; the HTTP delivery itself is at-least-once (consumers dedupe on event_id).
 */
final class OutboxRelay
{
    /**
     * Drain up to $limit due pending outbox rows into per-endpoint webhook
     * deliveries and dispatch their delivery jobs. Returns the number of outbox rows
     * processed (whether or not any endpoint was subscribed).
     */
    public function process(int $limit = 100): int
    {
        $messages = $this->claimPending(limit: $limit);

        foreach ($messages as $message) {
            $this->fanOut(message: $message);
        }

        return $messages->count();
    }

    /**
     * Atomically read and flip a batch of due pending outbox rows to 'dispatched'
     * under a row lock, so the same row is never relayed by two workers. The status
     * flip guarantees a given row is fanned out once per process() call even where the
     * lock degrades (e.g. SQLite).
     *
     * @return Collection<int, Outbox>
     */
    private function claimPending(int $limit): Collection
    {
        /** @var class-string<Outbox> $outboxClass */
        $outboxClass = Config::outboxModel();

        return DB::transaction(function () use ($outboxClass, $limit): Collection {
            /** @var Collection<int, Outbox> $messages */
            $messages = $outboxClass::query()
                ->due()
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $messages->each(static function (Outbox $message): void {
                $message->forceFill([
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                ])->save();
            });

            return $messages;
        });
    }

    /**
     * Create a pending WebhookDelivery for every active endpoint subscribed to the
     * outbox row's event and dispatch its delivery job.
     */
    private function fanOut(Outbox $message): void
    {
        foreach ($this->subscribedEndpoints(event: $message->event) as $endpoint) {
            $delivery = $this->createDelivery(message: $message, endpoint: $endpoint);

            Bus::dispatch(new DeliverWebhook(deliveryId: Identity::stringify($delivery->getKey())));
        }
    }

    /**
     * The active endpoints whose subscription list contains this event. events is a
     * JSON array, so subscription is matched in PHP to stay portable across drivers.
     *
     * @return Collection<int, WebhookEndpoint>
     */
    private function subscribedEndpoints(string $event): Collection
    {
        /** @var class-string<WebhookEndpoint> $endpointClass */
        $endpointClass = Config::webhookEndpointModel();

        return $endpointClass::query()
            ->active()
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => in_array($event, $endpoint->events, true))
            ->values();
    }

    private function createDelivery(Outbox $message, WebhookEndpoint $endpoint): WebhookDelivery
    {
        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = Config::webhookDeliveryModel();

        return $deliveryClass::query()->create([
            'endpoint_id' => $endpoint->getKey(),
            'event' => $message->event,
            'payload' => $message->payload,
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
        ]);
    }
}
