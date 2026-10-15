<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Illuminate\Contracts\Container\Container;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;
use Vaulter\Kernel;

/**
 * The synchronous half of the outbox (D29): {@see EventRecorder} calls it last, inside the transaction of the
 * operation, for every event — whatever modules are active. It writes the v_outbox row (unique by event_id: a
 * replayed occurrence writes nothing) and a pending delivery row for each outbox consumer of the sealed
 * registry that subscribes to the type. Consumer rows (v_outbox_consumers) are not read here: a consumer the
 * relay never registered still gets its rows, and reconciliation covers the processes that ran an older graph.
 *
 * Without consumers only the event is written; retention removes it.
 *
 * @internal
 */
final readonly class OutboxWriter
{
    public function __construct(
        private VaulterConfig $config,
        private DatabaseConfig $tables,
        private Kernel $kernel,
        private Container $container,
        private MetricsRecorder $metrics,
    ) {}

    public function append(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        return $this->metrics->timing(
            name: 'vaulter_outbox_stage_duration_seconds',
            operation: function () use ($event, $writer): SinkResult {
                $scope = $event->scope();
                $subject = $event->subject();
                $seq = $writer->shared(SinkWriter::JOURNAL_SEQ);
                $now = now();

                // The model applies its casts (JSON payload, dates), so the row matches what Eloquent would write.
                /** @var OutboxEvent $outbox */
                $outbox = new ($this->config->model('outbox'));
                $outbox->forceFill([
                    'event_id' => $event->eventId,
                    'tenant_id' => $scope->tenantId,
                    'scope_kind' => $scope->driveId === null ? OutboxEnvelope::SCOPE_TENANT : OutboxEnvelope::SCOPE_DRIVE,
                    'scope_id' => $scope->driveId === null ? null : (string) $scope->driveId,
                    'aggregate_type' => $subject->type,
                    'aggregate_id' => $subject->id,
                    'sequence' => $event->sequence(),
                    'type' => $event->type()->value,
                    'external' => $event->isExternal(),
                    'payload' => OutboxEnvelope::body($event, is_int($seq) ? $seq : null),
                    'created_at' => $now,
                ]);
                if (!$writer->insertOnce(table: $outbox->getTable(), row: $outbox->getAttributes(), uniqueColumn: 'event_id')) {
                    return SinkResult::Replayed;
                }

                $consumers = $this->subscribed($event);
                if ($consumers === []) {
                    return SinkResult::Stored;
                }
                $outboxSeq = $writer->connection()->table($outbox->getTable())->where('event_id', $event->eventId)->value('outbox_seq');
                $writer->connection()->table($this->tables->table(OutboxStore::DELIVERIES))->insert(array_map(
                    static fn (string $consumer): array => [
                        'event_id' => $event->eventId,
                        'consumer_id' => $consumer,
                        'outbox_seq' => $outboxSeq,
                        'state' => DeliveryState::Pending->value,
                        'attempts' => 0,
                        'next_attempt_at' => $now,
                        'lease_generation' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $consumers,
                ));

                return SinkResult::Stored;
            },
        );
    }

    /**
     * The ids of the consumers of the sealed registry that subscribe to the event's type.
     *
     * @return list<string>
     */
    private function subscribed(DomainEvent $event): array
    {
        $ids = [];
        foreach ($this->kernel->extensions()->contributions(OutboxConsumer::class) as $contribution) {
            $consumer = $this->container->make($contribution->implementation);
            if ($consumer instanceof OutboxConsumer && $consumer->subscribes($event->type())) {
                $ids[] = $contribution->id;
            }
        }

        return $ids;
    }
}
