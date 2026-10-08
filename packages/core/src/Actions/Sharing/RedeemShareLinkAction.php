<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;

/**
 * Count one successful opening of a share link (PLAN2.D47). A redemption is the
 * start of a link session, not a request: the visits that follow inside the session
 * do not count. The count is one conditional UPDATE, so a link at its budget, a
 * revoked link or a link rotated since it was resolved is refused atomically.
 *
 * @internal
 */
final readonly class RedeemShareLinkAction
{
    public function __construct(private NodeShareStoreRepository $shares) {}

    public function execute(NodeShare $share): bool
    {
        return $this->shares->consumeUse($share);
    }
}
