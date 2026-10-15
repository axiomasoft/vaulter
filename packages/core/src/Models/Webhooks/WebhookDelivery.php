<?php

declare(strict_types=1);

namespace Vaulter\Models\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A single delivery attempt of an event to a webhook endpoint, with retry
 * bookkeeping. At-least-once with exponential
 * backoff: a non-2xx parks it 'failed' with next_retry_at on the backoff schedule;
 * the relay's due-retry sweep re-queues it; past the attempt budget it terminalises
 * 'dead' (DLQ) for inspection. event_id names the occurrence; an occurrence reaches
 * an endpoint through at most one row (UNIQUE(event_id, endpoint_id)).
 *
 * Swappable — point configuration key vaulter.models.webhook_delivery at a subclass.
 *
 * @property int|string           $id
 * @property int|string           $endpoint_id
 * @property string|null          $event_id
 * @property string               $event
 * @property array<string, mixed> $payload
 * @property string               $status
 * @property int                  $attempts
 * @property Carbon|null          $next_retry_at
 * @property string|null          $claim_token
 * @property Carbon|null          $claim_until
 * @property string|null          $last_error
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 *
 * @method static Builder<static> query()
 */
#[Internal]
class WebhookDelivery extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Fully guarded: a delivery-attempt row — every column is
     * system-computed retry bookkeeping. The relay writes it with forceFill()
     * or a bulk insert, never from request input.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('webhook_deliveries');
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        /** @var class-string<WebhookEndpoint> $related */
        $related = app(VaulterConfig::class)->model('webhook_endpoint');

        return $this->belongsTo(related: $related, foreignKey: 'endpoint_id');
    }

    /**
     * The deliveries the retry sweep re-queues: 'failed' rows whose backoff has
     * elapsed, and 'pending' rows whose job was apparently lost — still unattempted
     * $pendingGraceSeconds after they became due. The sweep passes the relay claim
     * window, so it does not race the job the relay dispatched right after inserting
     * the row. Rows a live worker holds and terminal rows (delivered|dead) are
     * excluded.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeDueForRetry(Builder $query, int $pendingGraceSeconds = 0): Builder
    {
        $now = now();
        $lostBefore = $now->copy()->subSeconds(max($pendingGraceSeconds, 0));

        return $query
            ->whereNotNull('next_retry_at')
            ->where(static fn (Builder $due): Builder => $due
                ->where(static fn (Builder $failed): Builder => $failed
                    ->where('status', 'failed')
                    ->where('next_retry_at', '<=', $now))
                ->orWhere(static fn (Builder $pending): Builder => $pending
                    ->where('status', 'pending')
                    ->where('next_retry_at', '<=', $lostBefore)))
            ->where(static fn (Builder $claim): Builder => $claim
                ->whereNull('claim_until')
                ->orWhere('claim_until', '<=', $now));
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
            'claim_until' => 'datetime',
        ];
    }
}
