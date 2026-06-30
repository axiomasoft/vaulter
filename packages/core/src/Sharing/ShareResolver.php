<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Vaulter\Models\Node;
use Vaulter\Models\NodeShare;
use Vaulter\Models\Storage;
use Vaulter\Repositories\NodeShareReadRepository;
use Vaulter\Repositories\StorageReadRepository;
use Vaulter\Support\ShareToken;

/**
 * Resolves a bearer share-token into a {@see ShareContext} (§2.7 / M2c) — the
 * SEPARATE sharing pass that runs INSTEAD of the identity ACL. Given a raw token it:
 *
 *   1. hashes the token (sha256) and looks up the LIVE share by token_hash (not
 *      revoked, not expired) — the raw token never touches the DB;
 *   2. re-verifies the match timing-safe (hash_equals) — defence-in-depth so the
 *      compare can never be a probe-able side channel;
 *   3. checks the use budget (use_count < max_uses);
 *   4. enforces the optional password gate and the optional require-login gate;
 *   5. resolves the target node and INTERSECTS it with item-safety — the node must
 *      not be trashed or soft-deleted and must belong to a real storage (tenant
 *      boundary). A share on an unsafe node resolves to NotFound, indistinguishable
 *      from a wrong token.
 *
 * The produced ShareContext grants EXACTLY the share's bitmask on its node-set and
 * NOTHING inherited from any identity. This evaluator is read-only — it persists
 * nothing (the atomic use_count increment is owned by the Action/store-repo on the
 * consuming endpoints) and never accepts a Request.
 */
final class ShareResolver
{
    public function __construct(
        private readonly NodeShareReadRepository $shares,
        private readonly StorageReadRepository $storages,
    ) {}

    /**
     * Resolve a raw bearer token for the (optional) acting host user and the
     * (optional) supplied password. Returns a ShareResult whose status the public
     * HTTP layer translates to 404 / 401 / proceed.
     */
    public function resolve(string $token, ?Authenticatable $actor = null, ?string $password = null): ShareResult
    {
        $now = Carbon::now();
        $tokenHash = ShareToken::hash($token);

        $share = $this->shares->findLiveByTokenHash($tokenHash, $now);

        if (!$share instanceof NodeShare) {
            return ShareResult::notFound();
        }

        // Defence-in-depth: the lookup already matched by hash, but re-verify with a
        // constant-time compare so the resolve path is never a timing oracle.
        if (!ShareToken::hashesMatch($share->token_hash, $tokenHash)) {
            return ShareResult::notFound();
        }

        // The use budget is part of liveness — an exhausted share is as good as
        // expired (NotFound, not a distinct leak).
        if ($share->isExhausted()) {
            return ShareResult::notFound();
        }

        if ($share->require_login && !$actor instanceof Authenticatable) {
            return ShareResult::loginRequired();
        }

        if ($share->hasPassword() && !$this->passwordMatches($share, $password)) {
            return ShareResult::passwordRequired();
        }

        $node = $this->safeTargetNode($share);

        if (!$node instanceof Node) {
            // Item-safety failed (trashed / soft-deleted / no storage) — opaque 404.
            return ShareResult::notFound();
        }

        return ShareResult::ok(new ShareContext(
            share: $share,
            node: $node,
            permissions: $share->permissions,
        ));
    }

    /**
     * The share's target node, but ONLY when it passes item-safety (§2.7): not
     * trashed, not soft-deleted, and attached to a real storage (the tenant
     * boundary). Returns null otherwise so a share on an unsafe node is opaque.
     */
    private function safeTargetNode(NodeShare $share): ?Node
    {
        /** @var Node|null $node */
        $node = $share->node()->first();

        if (!$node instanceof Node) {
            return null;
        }

        // Trashed (soft-trash) or soft-deleted nodes are never publicly reachable.
        // trashed() is the Laravel SoftDeletes flag (deleted_at set); trashed_at is
        // the distinct Drive soft-trash column (§2.10).
        if ($node->trashed_at !== null || $node->trashed()) {
            return null;
        }

        // The storage must exist (tenant/storage boundary) — a detached node is a
        // corrupt state, never a public surface.
        if (!$this->storages->find($node->storage_id) instanceof Storage) {
            return null;
        }

        return $node;
    }

    private function passwordMatches(NodeShare $share, ?string $password): bool
    {
        if ($password === null || $password === '' || $share->password_hash === null) {
            return false;
        }

        return Hash::check($password, $share->password_hash);
    }
}
