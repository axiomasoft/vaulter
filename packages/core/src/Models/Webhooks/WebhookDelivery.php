<?php

declare(strict_types=1);

namespace Vaulter\Models\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * A single delivery attempt of an event to a webhook endpoint, with retry
 * bookkeeping (master plan §2.11 / D14 / M3b). At-least-once with exponential
 * backoff: a non-2xx parks it 'failed' with next_retry_at on the backoff schedule;
 * the relay's due-retry sweep re-queues it; past the attempt budget it terminalises
 * 'dead' (DLQ) for inspection.
 *
 * Swappable — point config('vaulter.models.webhook_delivery') at a subclass.
 *
 * @property int|string           $id
 * @property int|string           $endpoint_id
 * @property string               $event
 * @property array<string, mixed> $payload
 * @property string               $status
 * @property int                  $attempts
 * @property Carbon|null          $next_retry_at
 * @property string|null          $last_error
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 *
 * @method static Builder<static> query()
 */
class WebhookDelivery extends Model
{
    use HasVaulterKeys;

    /**
     * Fully guarded (F-P1.7-04): a delivery-attempt row — every column is
     * system-computed retry bookkeeping. The sole writer, OutboxRelay::
     * createDelivery(), already uses forceFill() exclusively.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('webhook_deliveries');
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        /** @var class-string<WebhookEndpoint> $related */
        $related = Config::webhookEndpointModel();

        return $this->belongsTo(related: $related, foreignKey: 'endpoint_id');
    }

    /**
     * The deliveries whose backoff window has elapsed and are still retryable
     * (status in pending|failed, next_retry_at <= now). The relay sweep re-queues
     * these; terminal rows (delivered|dead) are excluded.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeDueForRetry(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now());
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }
}
