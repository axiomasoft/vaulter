<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
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
 */
#[Internal]
final readonly class OutboxWriter
{
    public function __construct(
        private VaulterConfig $config,
        private DatabaseConfig $tables,
        private IdsConfig $ids,
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
                // PostgreSQL aborts the operation transaction when this value cannot be cast into the host-key column.
                $this->guardTenantId($scope->tenantId, $writer->connection()->getDriverName());
                $subject = $event->subject();
                $seq = $writer->shared(SinkWriter::JOURNAL_SEQ);
                $now = now();

                // The model applies its casts (JSON payload, dates), so the row matches what Eloquent would write.
                /** @var OutboxEvent $outbox */
                $outbox = new ($this->config->outboxModel());
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

                // A smaller sequence can still commit for one transaction window after it was allocated.
                // Stamp only after this INSERT returned: payload preparation and the INSERT itself may have waited.
                // Both writes share the operation's transaction, so no committed row is left unstaged.
                $writer->connection()->table($outbox->getTable())->where('event_id', $event->eventId)
                    ->update(['staged_at' => now()->format('Y-m-d H:i:s.u')]);

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
     * Null fits the nullable column. Anything else must match the column {@see IdsConfig::hostKeys()} builds:
     * varchar(64), a non-negative bigint (signed on PostgreSQL, unsigned on MySQL), a uuid, or char(26).
     * The varchar and char limits count characters, including multibyte tenant keys.
     * A value that already fits is stored unchanged.
     */
    private function guardTenantId(?string $tenantId, string $driver): void
    {
        if ($tenantId === null || $this->tenantFits($tenantId, $driver)) {
            return;
        }

        throw new InvalidArgumentException('[Vaulter] The tenant id does not fit ids.host_keys ['.$this->ids->hostKeys().'].');
    }

    private function tenantFits(string $tenantId, string $driver): bool
    {
        return match ($this->ids->hostKeys()) {
            'string' => mb_strlen($tenantId, 'UTF-8') <= 64,
            'bigint' => self::fitsBigint($tenantId, $driver),
            'uuid' => Str::isUuid($tenantId),
            'ulid' => mb_strlen($tenantId, 'UTF-8') <= 26,
        };
    }

    /** Non-negative digits, capped at the signed bigint maximum, or the unsigned maximum on MySQL. */
    private static function fitsBigint(string $tenantId, string $driver): bool
    {
        if (preg_match('/^[0-9]{1,20}$/', $tenantId) !== 1) {
            return false;
        }
        $max = in_array($driver, ['mysql', 'mariadb'], true) ? '18446744073709551615' : '9223372036854775807';
        $normalized = ltrim($tenantId, '0');
        if ($normalized === '') {
            return true;
        }

        return strlen($normalized) < strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) <= 0);
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
