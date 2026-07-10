<?php

declare(strict_types=1);

namespace Vaulter\Models\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * A transactional-outbox row (master plan §2.11 / D14 / M3b): a domain event
 * durably staged for reliable webhook fan-out. event_id is UNIQUE — the SYNCHRONOUS
 * half (the OutboxRecorder) is idempotent by it, so the same event never stages a
 * duplicate row, and the relay drains each row exactly once.
 *
 * Swappable — point config('vaulter.models.outbox') at a subclass for tenant
 * scoping or extra behaviour.
 *
 * @property int|string           $id
 * @property string               $event_id
 * @property string               $panel
 * @property string|null          $topic
 * @property string               $event
 * @property array<string, mixed> $payload
 * @property Carbon               $available_at
 * @property Carbon|null          $dispatched_at
 * @property int                  $attempts
 * @property string               $status
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 *
 * @method static Builder<static> query()
 */
class Outbox extends Model
{
    use HasVaulterKeys;

    /**
     * Fully guarded (F-P1.8-02): the transactional-outbox staging row — every
     * column is system-computed (event_id, payload, status, retry bookkeeping).
     * The sole writer, OutboxRecorder::stage(), already uses forceFill()
     * exclusively.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('outbox');
    }

    /**
     * The pending rows that are due (available_at <= now), oldest-first — the
     * canonical relay-drain order (§2.11). The relay claims a batch from this scope.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('available_at');
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
            'dispatched_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
