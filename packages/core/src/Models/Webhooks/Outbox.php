<?php

declare(strict_types=1);

namespace Vaulter\Models\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A transactional-outbox row (master plan §2.11 / D14 / M3b): a domain event
 * durably staged for reliable webhook fan-out, its payload the webhook envelope.
 * event_id is UNIQUE — the OutboxRecorder writes one row per occurrence, so the same
 * event never stages a duplicate row.
 *
 * status is 'pending' until the relay has written a delivery for every eligible
 * endpoint and 'completed' afterwards (fanned_out_at, delivery_count). While a relay
 * worker fans the row out it holds claim_token until claim_until; fanout_cursor is
 * the last endpoint key already materialized, so a successor resumes after it.
 * legacy_fanout marks rows the pre-1.0 relay settled without that guarantee.
 *
 * Swappable — point configuration key vaulter.models.outbox at a subclass for tenant
 * scoping or extra behaviour.
 *
 * @property int|string           $id
 * @property string               $event_id
 * @property string               $profile
 * @property int|string|null      $tenant_id
 * @property int|string|null      $drive_id
 * @property string|null          $aggregate_type
 * @property string|null          $aggregate_id
 * @property int|null             $sequence
 * @property string|null          $topic
 * @property string               $event
 * @property array<string, mixed> $payload
 * @property Carbon               $available_at
 * @property int                  $attempts
 * @property string               $status
 * @property string|null          $claim_token
 * @property int                  $claim_generation
 * @property Carbon|null          $claim_until
 * @property string|null          $fanout_cursor
 * @property Carbon|null          $fanned_out_at
 * @property int                  $delivery_count
 * @property bool                 $legacy_fanout
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 *
 * @method static Builder<static> query()
 */
class Outbox extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Fully guarded (F-P1.8-02): the transactional-outbox staging row — every
     * column is system-computed (event_id, payload, status, retry bookkeeping).
     * The sole writer, OutboxRecorder::stage(), fills it with forceFill().
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('outbox');
    }

    /**
     * The pending rows that are due (available_at <= now) and not leased by a live
     * relay worker, oldest-first — the relay-drain order (§2.11). The relay claims
     * a batch from this scope.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->where(static fn (Builder $claim): Builder => $claim
                ->whereNull('claim_until')
                ->orWhere('claim_until', '<=', now()))
            ->orderBy('available_at')
            ->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'attempts' => 'integer',
            'claim_generation' => 'integer',
            'claim_until' => 'datetime',
            'fanned_out_at' => 'datetime',
            'delivery_count' => 'integer',
            'legacy_fanout' => 'boolean',
        ];
    }
}
