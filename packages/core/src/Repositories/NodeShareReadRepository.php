<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Models\Node;
use Vaulter\Models\NodeShare;
use Vaulter\Support\Config;

/**
 * Read-side queries over v_node_shares (§2.7 / M2c). It resolves a LIVE share by
 * its token hash (the bearer lookup) and lists the shares on a node for the owner
 * surface. Expiry + revocation are enforced HERE, read-time — an expired or
 * revoked share never reaches the resolver. The lookup is by token_hash; the raw
 * token never touches the DB.
 */
final class NodeShareReadRepository
{
    /**
     * The LIVE share whose token hashes to $tokenHash (not revoked, not expired),
     * or null. The caller still runs the timing-safe compare, the use-budget check
     * and the item-safety intersection — this only narrows to the live row.
     */
    public function findLiveByTokenHash(string $tokenHash, Carbon $now): ?NodeShare
    {
        $model = Config::nodeShareModel();

        /** @var NodeShare|null $share */
        $share = $model::query()
            ->where('token_hash', $tokenHash)
            ->live($now)
            ->first();

        return $share;
    }

    /**
     * The share by its primary key, or null. Used by the owner revoke path (which
     * additionally checks it belongs to the authorized node).
     */
    public function find(int|string $id): ?NodeShare
    {
        $model = Config::nodeShareModel();

        return $model::query()->find($id);
    }

    /**
     * Every share on a node for the owner listing (§2.7), newest first. Returns a
     * Builder so the controller paginates it; the Resource serialises prefix/hash
     * only — never the raw token (which is unrecoverable from these rows anyway).
     *
     * @return Builder<NodeShare>
     */
    public function forNode(Node $node): Builder
    {
        $model = Config::nodeShareModel();

        return $model::query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('created_at');
    }
}
