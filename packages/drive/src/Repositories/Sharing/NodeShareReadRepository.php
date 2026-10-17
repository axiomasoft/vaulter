<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Sharing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side queries over v_node_shares. It resolves a LIVE share by
 * its token hash (the bearer lookup) and lists the shares on a node for the owner
 * surface. Expiry + revocation are enforced HERE, read-time — an expired or
 * revoked share never reaches the resolver. The lookup is by token_hash; the raw
 * token never touches the DB.
 */
#[Internal]
final class NodeShareReadRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * The LIVE share whose token hashes to $tokenHash (not revoked, not expired),
     * or null. The caller still runs the timing-safe compare, the use-budget check
     * and the item-safety intersection — this only narrows to the live row.
     */
    public function findLiveByTokenHash(string $tokenHash, Carbon $now): ?NodeShare
    {
        $model = $this->vaulterConfig->model('node_share');

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
        $model = $this->vaulterConfig->model('node_share');

        return app(TenancyBoundary::class)->constrain($model::query(), 'node.storage')->find($id);
    }

    /**
     * Every share on a node for the owner listing, newest first. Returns a
     * Builder so the controller paginates it; the Resource serialises prefix/hash
     * only — never the raw token (which is unrecoverable from these rows anyway).
     *
     * @return Builder<NodeShare>
     */
    public function forNode(Node $node): Builder
    {
        $model = $this->vaulterConfig->model('node_share');

        return app(TenancyBoundary::class)->constrain($model::query(), 'node.storage')
            ->where('node_id', $node->getKey())
            ->orderByDesc('created_at');
    }
}
