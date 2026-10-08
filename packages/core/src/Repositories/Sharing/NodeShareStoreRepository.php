<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

/**
 * Write-side row work for v_node_shares (§2.7 / M2c). It persists ONLY the token's
 * sha256 hash + prefix (never the raw token), and owns the atomic, max_uses-bounded
 * use-count increment. Opens NO transactions and dispatches NO events — the Action
 * does. Authorization and token minting/hashing happen upstream (Action); this is
 * pure row work.
 */
final class NodeShareStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * Persist a new bearer share on a node. The caller has already hashed the raw
     * token (only $tokenHash + $tokenPrefix reach the DB) and hashed any password.
     */
    public function create(
        Node $node,
        string $tokenHash,
        string $tokenPrefix,
        Role $role,
        ShareAudience $audience,
        string $scope,
        ?string $passwordHash,
        ?Carbon $expiresAt,
        ?int $maxUses,
        int|string|null $grantedBy,
    ): NodeShare {
        $model = $this->vaulterConfig->model('node_share');

        // forceFill (P1.4/F-P1.8-02): every key here — including token_hash and
        // use_count, which NodeShare deliberately excludes from $fillable — is a
        // server-computed literal, never raw request input.
        /** @var NodeShare $share */
        $share = new $model;
        $share->forceFill([
            'tenant_id' => $node->tenant_id,
            'node_id' => $node->getKey(),
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'role_key' => $role->key,
            'role_version' => $role->version,
            'audience' => $audience,
            'token_generation' => 1,
            'scope' => $scope,
            'password_hash' => $passwordHash,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'use_count' => 0,
            'granted_by' => $grantedBy,
        ]);
        $share->save();

        return $share->refresh();
    }

    /**
     * Change the mutable fields of a link (audience, expiry, use budget, password
     * hash). The token, the role and the generation never move here.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(NodeShare $share, array $attributes): NodeShare
    {
        if ($attributes !== []) {
            $share->forceFill($attributes)->save();
        }

        return $share->refresh();
    }

    /**
     * Swap the link's token for a new one and move it to the next generation, so
     * the old token and every session opened with it stop working at once.
     */
    public function rotate(NodeShare $share, string $tokenHash, string $tokenPrefix): NodeShare
    {
        $share->forceFill([
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'token_generation' => $share->token_generation + 1,
        ])->save();

        return $share->refresh();
    }

    /**
     * Revoke a share by stamping revoked_at (idempotent — a re-revoke is a no-op).
     * Returns true when this call actually flipped it from live to revoked.
     */
    public function revoke(NodeShare $share, Carbon $now): bool
    {
        if ($share->revoked_at !== null) {
            return false;
        }

        $share->forceFill(['revoked_at' => $now])->save();

        return true;
    }

    /**
     * Atomically redeem one use of a share, bounded by max_uses (§2.7). A single
     * conditional UPDATE increments use_count only while the link is not revoked,
     * is still on the generation the caller resolved and is still below max_uses
     * (or max_uses is null/unbounded), so two concurrent visits can never push it
     * past the budget and a rotation that commits first wins. Returns true when a
     * use was redeemed.
     */
    public function consumeUse(NodeShare $share): bool
    {
        $model = $this->vaulterConfig->model('node_share');

        $query = $model::query()
            ->whereKey($share->getKey())
            ->where('token_generation', $share->token_generation)
            ->whereNull('revoked_at');

        if ($share->max_uses !== null) {
            // The atomic guard: only rows still under budget are bumped.
            $query->whereColumn('use_count', '<', 'max_uses');
        }

        $affected = $query->increment('use_count');

        return $affected > 0;
    }
}
