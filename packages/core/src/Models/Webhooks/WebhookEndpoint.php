<?php

declare(strict_types=1);

namespace Vaulter\Models\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * A subscriber endpoint receiving signed webhook deliveries for the events it is
 * subscribed to (master plan §2.11 / D14 / M3b). signing_secret is generated at
 * registration and shown to the owner exactly once; the deliveries are HMAC-SHA256
 * signed with it. failure_count drives the circuit breaker (auto-disable past the
 * threshold).
 *
 * Swappable — point config('vaulter.models.webhook_endpoint') at a subclass.
 *
 * @property int|string         $id
 * @property string             $url
 * @property array<int, string> $events
 * @property string             $signing_secret
 * @property bool               $is_active
 * @property Carbon|null        $last_triggered_at
 * @property int                $failure_count
 * @property Carbon|null        $created_at
 * @property Carbon|null        $updated_at
 *
 * @method static Builder<static> query()
 */
class WebhookEndpoint extends Model
{
    use HasVaulterKeys;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('webhook_endpoints');
    }

    /**
     * The deliveries fanned out to this endpoint.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        /** @var class-string<WebhookDelivery> $related */
        $related = Config::webhookDeliveryModel();

        return $this->hasMany(related: $related, foreignKey: 'endpoint_id');
    }

    /**
     * The active endpoints — the only ones the relay fans events out to.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }
}
