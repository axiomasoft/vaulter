<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Schema\IdStrategyResolver;

/**
 * The SYNCHRONOUS half of the transactional outbox:
 * it stages ONE v_outbox row for a domain event, with the webhook envelope as its
 * payload. Only {@see EventRecorder} calls it, inside the transaction of the Action
 * that caused the event, so the row commits or rolls back with the change.
 *
 * The row is unique by event_id: staging the same occurrence twice inserts it once,
 * and the relay fans each row out to the subscribed endpoints.
 *
 * Thin side-effect service (store-only): it persists an outbox row and nothing else.
 * Not final — a host may subclass it, and a test can replace it with a failing sink.
 *
 * @internal
 */
class OutboxRecorder
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly MetricsRecorder $metrics,
        private readonly VaulterDatabase $database,
        private readonly IdStrategyResolver $ids,
    ) {}

    /**
     * Stage the outbox row of $event; false when the occurrence is already staged.
     *
     * @param array<string, mixed> $envelope {@see EventEnvelope::fromEvent()}
     */
    public function stage(DomainEvent $event, array $envelope): bool
    {
        return $this->metrics->timing(
            name: 'vaulter_outbox_stage_duration_seconds',
            operation: function () use ($event, $envelope): bool {
                $scope = $event->scope();
                $subject = $event->subject();
                $now = now();

                // The model applies its casts (JSON payload, dates) so the row matches
                // what Eloquent would have written.
                /** @var Outbox $outbox */
                $outbox = new ($this->vaulterConfig->model('outbox'));
                $outbox->forceFill([
                    'event_id' => $event->eventId,
                    'profile' => $scope->profile,
                    'tenant_id' => $scope->tenantId,
                    'drive_id' => $scope->driveId,
                    'aggregate_type' => $subject->type,
                    'aggregate_id' => $subject->id,
                    'sequence' => $event->sequence(),
                    'topic' => $subject->id,
                    'event' => $event->type()->value,
                    'payload' => $envelope,
                    'available_at' => $now,
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = $this->ids->resolve()->newId();
                $row = $id === null ? $outbox->getAttributes() : [$outbox->getKeyName() => $id, ...$outbox->getAttributes()];

                return $this->database->insertOnce(table: $outbox->getTable(), row: $row, uniqueColumn: 'event_id');
            },
        );
    }
}
