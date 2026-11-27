<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Observability\OtlpOpenTelemetryTraceRecorder;
use Vaulter\Support\Identity;

/**
 * One row of the neutral transactional outbox: a domain event written in the transaction of the
 * operation that caused it, whatever modules are active. event_id is UNIQUE — the outbox writer stores one
 * row per occurrence. outbox_seq is the internal monotonic order of the outbox (independent of the id
 * strategy); payload is the envelope every consumer receives. The delivery state of each consumer lives in
 * v_outbox_deliveries, not here.
 *
 * Swappable — point configuration key vaulter.models.outbox at a subclass for extra behaviour.
 *
 * @property int                  $outbox_seq
 * @property string               $event_id
 * @property int|string|null      $tenant_id
 * @property string               $scope_kind
 * @property string|null          $scope_id
 * @property string|null          $aggregate_type
 * @property string|null          $aggregate_id
 * @property int|null             $sequence
 * @property string               $type
 * @property bool                 $external
 * @property array<string, mixed> $payload
 * @property Carbon               $created_at
 * @property Carbon|null          $staged_at      set after sequence allocation, before the operation commits
 *
 * @method static Builder<static> query()
 */
#[Internal]
class OutboxEvent extends Model
{
    use GuardsWrites;
    use ResolvesVaulterConnection;

    public const null UPDATED_AT = null;

    /** @var string */
    protected $primaryKey = 'outbox_seq';

    /**
     * Fully guarded: every column is system-computed; the sole writer, OutboxWriter, fills it with forceFill().
     *
     * @var array<string>
     */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('outbox');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'outbox_seq' => 'integer',
            'sequence' => 'integer',
            'external' => 'boolean',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Staging is an absolute UTC instant, including when the application's local hour repeats at DST.
     * Keep its precision independent of the model's occurrence timestamp format.
     */
    protected function getStagedAtAttribute(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    protected function setStagedAtAttribute(DateTimeInterface|string|null $value): void
    {
        $this->attributes['staged_at'] = $value === null ? null : Carbon::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    public function traceparent(): ?string
    {
        $raw = $this->payload['_traceparent'] ?? $this->payload['traceparent'] ?? null;

        return is_string($raw) && OtlpOpenTelemetryTraceRecorder::parseTraceparent($raw) !== null
            ? $raw
            : null;
    }

    public function toEnvelope(): OutboxEnvelope
    {
        $payload = $this->payload;
        if (array_key_exists('_traceparent', $payload)) {
            unset($payload['_traceparent']);
        }

        return new OutboxEnvelope(
            eventId: $this->event_id,
            outboxSeq: $this->outbox_seq,
            type: $this->type,
            external: $this->external,
            tenantId: $this->tenant_id === null ? null : Identity::stringify($this->tenant_id),
            scopeKind: $this->scope_kind,
            scopeId: $this->scope_id,
            aggregateType: $this->aggregate_type,
            aggregateId: $this->aggregate_id,
            sequence: $this->sequence,
            createdAt: CarbonImmutable::instance($this->created_at),
            payload: $payload,
        );
    }
}
