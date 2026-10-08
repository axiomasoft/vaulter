<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Sharing;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Data\Sharing\ShareLinkUpdate;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

interface ShareOperations
{
    /** @return Collection<int, NodeShare> */
    public function list(Node|int|string $node): Collection;

    public function create(Node|int|string $node, ShareLinkInput $input): MintedShareLink;

    /**
     * Change expiry, use budget, password or audience of a live link. The token and
     * the role stay; use rotate() for a new token.
     */
    public function update(Node|int|string $node, NodeShare|int|string $share, ShareLinkUpdate $changes): NodeShare;

    /**
     * Replace the link's token. The old token stops working immediately; the new one
     * is in the result and nowhere else.
     */
    public function rotate(Node|int|string $node, NodeShare|int|string $share): MintedShareLink;

    public function revoke(Node|int|string $node, NodeShare|int|string $share): void;
}
