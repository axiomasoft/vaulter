<?php

declare(strict_types=1);

namespace Vaulter\Models\Sharing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;
use Vaulter\Models\Drive\Node;

/**
 * A bearer share-link on a node — the SEPARATE public sharing
 * channel. A share grants exactly the abilities of its role (role_key, role_version)
 * on its node-set (the node itself for scope=item; the node + its descendants for
 * scope=subtree), evaluated in its OWN pass intersected with item-safety — it is
 * NEVER unioned with the identity ACL. Its audience says who may open it and its
 * token_generation rises each time the token is rotated.
 *
 * The raw bearer token is NEVER stored: only its sha256 `token_hash` (a UNIQUE
 * char(64)) plus a short, non-secret `token_prefix` for owner-side UX lookup. The
 * raw token is returned to the creator exactly once at mint time and is never
 * re-derivable from this row.
 *
 * Swappable — point configuration key vaulter.models.node_share at a subclass for tenant
 * scoping or extra behaviour.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property string          $token_hash
 * @property string          $token_prefix
 * @property string          $role_key
 * @property int             $role_version
 * @property ShareAudience   $audience
 * @property int             $token_generation
 * @property GrantScope      $scope
 * @property string|null     $password_hash
 * @property Carbon|null     $expires_at
 * @property int|null        $max_uses
 * @property int             $use_count
 * @property Carbon|null     $revoked_at
 * @property int|string|null $granted_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read Node $node
 */
#[Internal]
class NodeShare extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Closed allow-list: the share's own audience/lifecycle shape —
     * never the role, token_hash/token_prefix (the bearer secret's hash),
     * token_generation nor use_count/granted_by/node_id/tenant_id
     * (server-computed/security). The
     * sole writer, NodeShareStoreRepository, uses forceFill() exclusively and
     * is unaffected by this list.
     *
     * @var list<string>
     */
    protected $fillable = [
        'audience',
        'scope',
        'password_hash',
        'expires_at',
        'max_uses',
    ];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('node_shares');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('node'), 'node_id');
    }

    /**
     * Constrain a query to LIVE shares: not revoked and not expired as of $now
     * (both enforced read-time, never a cron — an expired/revoked share simply
     * never resolves). A null expires_at never expires.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query, Carbon $now): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(static function (Builder $inner) use ($now): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * The role this link grants, at the version it was created with.
     */
    public function role(): Role
    {
        return (new RoleRegistry)->get($this->role_key, $this->role_version);
    }

    /**
     * Whether the share has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether the share has passed its expiry as of now.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the share's use budget is exhausted (use_count >= max_uses). A null
     * max_uses is unbounded.
     */
    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->use_count >= $this->max_uses;
    }

    /**
     * Whether the share is resolvable right now: live (not revoked, not expired)
     * and within its use budget.
     */
    public function isResolvable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired() && !$this->isExhausted();
    }

    /**
     * Whether the share is password-gated.
     */
    public function hasPassword(): bool
    {
        return $this->password_hash !== null && $this->password_hash !== '';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'scope' => GrantScope::class,
            'audience' => ShareAudience::class,
            'role_version' => 'integer',
            'token_generation' => 'integer',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'use_count' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }
}
