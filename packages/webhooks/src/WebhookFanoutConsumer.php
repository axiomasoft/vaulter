<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\Exceptions\LeaseLostException;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenantIdentityPredicate;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The webhooks module's outbox consumer `webhooks.fanout` (D29, durable): turns each external event into one
 * WebhookDelivery per eligible endpoint and queues their jobs.
 *
 * Endpoints are materialized fanout_chunk at a time, each chunk in its own transaction. The chunk first saves its
 * last endpoint key as the delivery's continuation — a write fenced by the outbox lease, which also locks the
 * delivery row until the chunk commits — so a worker whose lease was taken over writes nothing, and a successor
 * resumes after the last committed chunk. Jobs are queued only after the chunk commits; a job lost after the
 * commit is recovered by the retry sweep (WebhookRetryDispatcher). UNIQUE(event_id, endpoint_id) backs it up.
 *
 * Eligible endpoint: active, same tenant (null only matches null), drive filter unset or equal to the event's drive
 * (scope_kind drive), subscribed to the type, and created no later than the event — an endpoint registered
 * afterwards never receives older events. A save of a document without a checkpoint is written to the outbox as
 * not external and reaches no endpoint. Deliveries are at least once over HTTP; receivers dedupe on the event id.
 */
#[Internal]
final readonly class WebhookFanoutConsumer implements OutboxConsumer
{
    public const string ID = 'webhooks.fanout';

    public function __construct(
        private WebhooksConfig $webhooksConfig,
        private MetricsRecorder $metrics,
        private TraceRecorder $tracer,
        private VaulterDatabase $database,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Durable;
    }

    public function subscribes(EventTypeContract $type): bool
    {
        return $type->spec()->external;
    }

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult
    {
        if (!$envelope->external) {
            return ConsumeResult::done();
        }

        return $this->tracer->span(
            name: TracingSpanNames::WEBHOOK_RELAY,
            operation: function () use ($envelope, $control): ConsumeResult {
                try {
                    while (!$this->materializeChunk($envelope, $control)) {
                        // More endpoints remain after the chunk just committed.
                    }
                } catch (LeaseLostException $lost) {
                    return ConsumeResult::retry($lost->getMessage());
                }

                return ConsumeResult::done();
            },
            attributes: TracingAttributes::forTarget(targetId: 'webhook.pipeline', layer: 'action'),
        );
    }

    /**
     * One fenced fan-out step: true when no endpoint remains after it.
     *
     * @throws LeaseLostException when the delivery was taken over; the chunk is rolled back
     */
    private function materializeChunk(OutboxEnvelope $envelope, DeliveryControl $control): bool
    {
        $chunk = $this->webhooksConfig->relayFanoutChunk();

        // One attempt: a chunk that rolls back leaves the consume, so the next lease re-reads the continuation that
        // committed instead of the one this attempt saved.
        return $this->database->transaction(function () use ($envelope, $control, $chunk): bool {
            $endpoints = $this->eligibleEndpoints($envelope, $control->continuation())->limit($chunk)->get();
            $this->metrics->counter(name: 'vaulter_outbox_endpoints_scanned_total', amount: $endpoints->count());

            $last = $endpoints->last();
            $fenced = $last instanceof WebhookEndpoint
                ? $control->saveContinuation(Identity::stringify($last->getKey()))
                : $control->isCurrent();
            if (!$fenced) {
                throw new LeaseLostException('the webhooks.fanout delivery of '.$envelope->eventId.' was taken over');
            }

            $deliveryIds = $this->createDeliveries($envelope, array_values($endpoints->modelKeys()));
            $this->database->afterCommit(static function () use ($deliveryIds): void {
                foreach ($deliveryIds as $deliveryId) {
                    Bus::dispatch(new DeliverWebhook(deliveryId: $deliveryId));
                }
            });

            return $endpoints->count() < $chunk;
        }, attempts: 1);
    }

    /**
     * The next page of eligible endpoints after the continuation, in key order. Only keys are read: the delivery
     * copies the outbox payload.
     *
     * @return Builder<WebhookEndpoint>
     */
    private function eligibleEndpoints(OutboxEnvelope $envelope, ?string $after): Builder
    {
        /** @var class-string<WebhookEndpoint> $endpointClass */
        $endpointClass = $this->webhooksConfig->model('webhook_endpoint');
        $model = new $endpointClass;
        $key = $model->getKeyName();

        $query = $endpointClass::query()
            ->active()
            ->whereJsonContains('events', $envelope->type)
            ->where('created_at', '<=', $envelope->createdAt)
            ->select($key)
            ->orderBy($key);

        // Null-safe on every driver: "= NULL" would match nothing.
        if ($envelope->tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $column = $model->qualifyColumn('tenant_id');
            $query->where($column, $envelope->tenantId)
                ->whereRaw(new TenantIdentityPredicate($column, $model->getConnection()->getDriverName()), [$envelope->tenantId]);
        }

        $driveId = $envelope->scopeKind === OutboxEnvelope::SCOPE_DRIVE ? $envelope->scopeId : null;
        $query->where(static fn (Builder $drive): Builder => $driveId === null
            ? $drive->whereNull('drive_id')
            : $drive->whereNull('drive_id')->orWhere('drive_id', $driveId));

        if ($after !== null) {
            $query->where($key, '>', $model->getKeyType() === 'int' ? (int) $after : $after);
        }

        return $query;
    }

    /**
     * Insert the deliveries this occurrence does not have yet and return their keys. The fenced continuation write
     * holds the outbox delivery row, so two workers of the same occurrence cannot interleave; the unique index stays
     * the last word.
     *
     * @param  list<int|string> $endpointIds
     * @return list<string>
     */
    private function createDeliveries(OutboxEnvelope $envelope, array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        /** @var class-string<WebhookDelivery> $deliveryClass */
        $deliveryClass = $this->webhooksConfig->model('webhook_delivery');

        $existing = array_map(
            Identity::stringify(...),
            $deliveryClass::query()->where('event_id', $envelope->eventId)->whereIn('endpoint_id', $endpointIds)->pluck('endpoint_id')->all(),
        );

        $created = [];
        foreach ($endpointIds as $endpointId) {
            if (in_array(Identity::stringify($endpointId), $existing, true)) {
                continue;
            }

            // forceFill: WebhookDelivery is intentionally fully guarded — this is the consumer's own trusted seam,
            // never raw request input.
            /** @var WebhookDelivery $delivery */
            $delivery = new $deliveryClass;
            $delivery->forceFill([
                'endpoint_id' => $endpointId,
                'event_id' => $envelope->eventId,
                'event' => $envelope->type,
                'payload' => $envelope->payload,
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
