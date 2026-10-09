<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\Support\Identity;

/**
 * The transactional-outbox RELAY (master plan §2.11 / D14 / M3b): turns each due
 * outbox row into one WebhookDelivery per eligible endpoint and queues their jobs.
 *
 * Nothing is lost when a worker dies at any point:
 *  1. Claim — a short committed transaction leases due rows to this worker (a fresh
 *     claim_token, claim_generation + 1, claim_until). Rows stay 'pending'.
 *  2. Fan-out — endpoints are materialized fanout_chunk at a time, each chunk in its
 *     own transaction under the outbox row lock. A chunk commits only while the row
 *     still carries this worker's token, so a worker whose lease lapsed and was taken
 *     over writes nothing. fanout_cursor records the last endpoint key written; the
 *     chunk that finds fewer than fanout_chunk endpoints marks the row 'completed' in
 *     the same transaction, so 'completed' always means every delivery exists.
 *  3. Jobs are queued only after the chunk commits. A job lost after the commit is
 *     recovered by the retry sweep (WebhookRetryDispatcher).
 * A crashed worker's row becomes due again when its lease lapses, and the next worker
 * resumes after fanout_cursor; UNIQUE(event_id, endpoint_id) backs that up.
 *
 * Eligible endpoint: active, same tenant (null only matches null), drive filter unset
 * or equal to the event's drive, subscribed to the event, and created no later than
 * the event — an endpoint registered afterwards never receives older events.
 * Deliveries are at-least-once over HTTP; receivers dedupe on the event id.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly WebhooksConfig $webhooksConfig,
        private readonly MetricsRecorder $metrics,
        private readonly TraceRecorder $tracer,
        private readonly VaulterDatabase $database,
    ) {}

    /**
     * Claim up to $limit due outbox rows and fan each out to its eligible endpoints.
     * Returns the number of rows claimed (whether or not any endpoint was eligible).
     */
    public function process(int $limit = 100): int
    {
        return $this->tracer->span(
            name: TracingSpanNames::WEBHOOK_RELAY,
            operation: fn (): int => $this->metrics->timing(
                name: 'vaulter_outbox_relay_duration_seconds',
                operation: function () use ($limit): int {
                    $claims = $this->claim(limit: $limit);

                    foreach ($claims as $id => $token) {
                        $this->fanOut(id: $id, token: $token);
                        $this->metrics->counter(name: 'vaulter_outbox_relayed_total');
                    }

                    return count($claims);
                },
            ),
            attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'action'),
        );
    }

    /**
     * Lease a batch of due rows to this worker. Concurrent workers skip each other's
     * locked rows where the database can (SKIP LOCKED); the conditional UPDATE keeps
     * a row single-owner where it cannot (SQLite serialises writers).
     *
     * @return array<int|string, string> outbox key => claim token
     */
    private function claim(int $limit): array
    {
        /** @var class-string<Outbox> $outboxClass */
        $outboxClass = $this->vaulterConfig->model('outbox');

        return $this->database->transaction(function () use ($outboxClass, $limit): array {
            $query = $outboxClass::query()->due()->limit(max($limit, 1));
            $query = in_array($this->database->driver(), ['pgsql', 'mysql', 'mariadb'], true)
                ? $query->lock('for update skip locked')
                : $query->lockForUpdate();
            $keyName = $query->getModel()->getKeyName();

            $claims = [];
            foreach ($query->pluck($keyName) as $key) {
                $key = Identity::key($key);
                if ($key === null) {
                    continue;
                }

                $token = Str::random(32);
                $claimed = $outboxClass::query()
                    ->whereKey($key)
                    ->where('status', 'pending')
                    ->where(static fn (Builder $lease): Builder => $lease->whereNull('claim_until')->orWhere('claim_until', '<=', now()))
                    ->toBase()
                    ->increment('claim_generation', 1, [
                        'claim_token' => $token,
                        'claim_until' => now()->addSeconds($this->webhooksConfig->relayClaimSeconds()),
                    ]);

                if ($claimed === 1) {
                    $claims[$key] = $token;
                }
            }

            return $claims;
        });
    }

    /**
     * Materialize the row chunk by chunk until it completes or this worker loses it.
     */
    private function fanOut(int|string $id, string $token): void
    {
        do {
            $completed = $this->materializeChunk(id: $id, token: $token);
        } while ($completed === false);
    }

    /**
     * One fenced fan-out step. Returns true when the row completed, false when more
     * endpoints remain, null when the row is no longer this worker's (taken over,
     * completed or deleted) — nothing is written then.
     */
    private function materializeChunk(int|string $id, string $token): ?bool
    {
        /** @var class-string<Outbox> $outboxClass */
        $outboxClass = $this->vaulterConfig->model('outbox');
        $chunk = $this->webhooksConfig->relayFanoutChunk();

        return $this->database->transaction(function () use ($outboxClass, $id, $token, $chunk): ?bool {
            $message = $outboxClass::query()->whereKey($id)->lockForUpdate()->first();
            if (!$message instanceof Outbox || $message->status !== 'pending' || $message->claim_token !== $token) {
                return null;
            }

            $endpoints = $this->eligibleEndpoints(message: $message)->limit($chunk)->get();
            $this->metrics->counter(name: 'vaulter_outbox_endpoints_scanned_total', amount: $endpoints->count());

            $deliveryIds = $this->createDeliveries(message: $message, endpointIds: array_values($endpoints->modelKeys()));
            $completed = $endpoints->count() < $chunk;

            $last = $endpoints->last();
            $message->forceFill([
                'fanout_cursor' => $last instanceof WebhookEndpoint ? Identity::stringify($last->getKey()) : $message->fanout_cursor,
                'delivery_count' => $message->delivery_count + count($deliveryIds),
                ...($completed
                    ? ['status' => 'completed', 'fanned_out_at' => now(), 'claim_token' => null, 'claim_until' => null]
                    : ['claim_until' => now()->addSeconds($this->webhooksConfig->relayClaimSeconds())]),
            ])->save();

            $this->database->afterCommit(static function () use ($deliveryIds): void {
                foreach ($deliveryIds as $deliveryId) {
                    Bus::dispatch(new DeliverWebhook(deliveryId: $deliveryId));
                }
            });

            return $completed;
        });
    }

    /**
     * The next page of eligible endpoints after the row's fan-out cursor, in key
     * order. Only keys are read: the delivery copies the outbox payload.
     *
     * @return Builder<WebhookEndpoint>
     */
    private function eligibleEndpoints(Outbox $message): Builder
    {
        /** @var class-string<WebhookEndpoint> $endpointClass */
        $endpointClass = $this->vaulterConfig->model('webhook_endpoint');
        $model = new $endpointClass;
        $key = $model->getKeyName();

        $query = $endpointClass::query()
            ->active()
            ->whereJsonContains('events', $message->event)
            ->where('created_at', '<=', $message->created_at ?? $message->available_at)
            ->select($key)
            ->orderBy($key);

        // Null-safe on every driver: "= NULL" would match nothing.
        if ($message->tenant_id === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $message->tenant_id);
        }

        $driveId = $message->drive_id;
        $query->where(static fn (Builder $drive): Builder => $driveId === null
            ? $drive->whereNull('drive_id')
            : $drive->whereNull('drive_id')->orWhere('drive_id', $driveId));

        if ($message->fanout_cursor !== null) {
            $query->where($key, '>', $model->getKeyType() === 'int' ? (int) $message->fanout_cursor : $message->fanout_cursor);
        }

        return $query;
    }

    /**
     * Insert the deliveries this occurrence does not have yet and return their keys.
     * Every writer of an occurrence's deliveries holds its outbox row lock, so the
     * existence check cannot race; the unique index stays the last word.
     *
     * @param  list<int|string> $endpointIds
     * @return list<string>
     */
    private function createDeliveries(Outbox $message, array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = $this->vaulterConfig->model('webhook_delivery');

        $existing = array_map(
            Identity::stringify(...),
            $deliveryClass::query()->where('event_id', $message->event_id)->whereIn('endpoint_id', $endpointIds)->pluck('endpoint_id')->all(),
        );

        $created = [];
        foreach ($endpointIds as $endpointId) {
            if (in_array(Identity::stringify($endpointId), $existing, true)) {
                continue;
            }

            // forceFill (P1.4/F-P1.8-02): WebhookDelivery is intentionally fully
            // guarded — this is the relay's own trusted seam, never raw request input.
            /** @var WebhookDelivery $delivery */
            $delivery = new $deliveryClass;
            $delivery->forceFill([
                'endpoint_id' => $endpointId,
                'event_id' => $message->event_id,
                'event' => $message->event,
                'payload' => $message->payload,
                'status' => 'pending',
                'attempts' => 0,
                'next_retry_at' => now(),
            ]);
            $delivery->save();

            $created[] = Identity::stringify($delivery->getKey());
        }

        return $created;
    }
}
