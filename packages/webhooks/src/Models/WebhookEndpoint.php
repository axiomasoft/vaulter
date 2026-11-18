<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Webhooks\Configuration\WebhooksOptions;

/**
 * A subscriber endpoint receiving signed webhook deliveries for the events it is
 * subscribed to. signing_secret is generated at
 * registration and shown to the owner exactly once; the deliveries are signed with it
 * (Standard Webhooks, HMAC-SHA256 over its whsec_ key). After a rotation the previous
 * secret keeps signing until previous_secret_expires_at. failing_since marks the start
 * of the current unbroken run of failed attempts; once it is old enough the endpoint is
 * disabled (disabled_at, disabled_reason 'failing'), as an owner's deactivation is
 * ('manual'). tenant_id is the tenant the endpoint was registered in; it receives
 * only that tenant's events (null receives only events without a tenant). A set
 * drive_id narrows it further to the events of that drive.
 *
 * Swappable — point configuration key vaulter-webhooks.models.webhook_endpoint at a subclass.
 *
 * @property int|string         $id
 * @property string             $url
 * @property array<int, string> $events
 * @property string             $signing_secret
 * @property string|null        $previous_signing_secret
 * @property Carbon|null        $previous_secret_expires_at
 * @property bool               $is_active
 * @property Carbon|null        $last_triggered_at
 * @property int                $failure_count
 * @property Carbon|null        $failing_since
 * @property Carbon|null        $disabled_at
 * @property string|null        $disabled_reason
 * @property int                $version
 * @property int|string|null    $tenant_id
 * @property int|string|null    $drive_id
 * @property Carbon|null        $created_at
 * @property Carbon|null        $updated_at
 *
 * @method static Builder<static> query()
 */
#[Internal]
class WebhookEndpoint extends Model
{
    /** The aggregate of the operation catalogue the refusal of a stale endpoint write names. */
    public const string AGGREGATE = 'webhooks.endpoint';

    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Closed allow-list: NEVER the signing secrets (the HMAC keys,
     * ) nor the failure/disable bookkeeping — all server-only. The
     * sole writer, WebhookEndpointStoreRepository, uses forceFill() exclusively
     * and is unaffected by this list.
     *
     * @var list<string>
     */
    protected $fillable = [
        'url',
        'events',
        'is_active',
    ];

    /**
     * The HMAC keys never leave through toArray()/toJson(), whatever serializes the model.
     *
     * @var list<string>
     */
    protected $hidden = ['signing_secret', 'previous_signing_secret'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('webhook_endpoints');
    }

    /**
     * The deliveries fanned out to this endpoint.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        /** @var class-string<WebhookDelivery> $related */
        $related = app(WebhooksOptions::class)->model('webhook_delivery');

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
     * The secrets a delivery is signed with now: the current one first, then the previous
     * one while its rotation overlap has not ended.
     *
     * @return list<string>
     */
    public function activeSigningSecrets(): array
    {
        $secrets = [$this->signing_secret];

        if (
            is_string($this->previous_signing_secret) && $this->previous_signing_secret !== ''
            && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture()
        ) {
            $secrets[] = $this->previous_signing_secret;
        }

        return $secrets;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'events' => 'array',
            // Encrypted at rest: a DB dump/replica leak must not hand an
            // attacker the HMAC keys to forge signed deliveries. Laravel encrypts with
            // APP_KEY on write and decrypts transparently on read (WebhookSigner sees
            // the plaintext); the column is text-typed to hold the longer ciphertext.
            'signing_secret' => 'encrypted',
            'previous_signing_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'failure_count' => 'integer',
            'failing_since' => 'datetime',
            'disabled_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
