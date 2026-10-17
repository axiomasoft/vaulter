<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Sharing;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;

#[Api]
interface ShareOperations
{
    /** @return list<ShareLinkSnapshot> */
    public function list(NodeSnapshot|string|int $node): array;

    public function create(NodeSnapshot|string|int $node, ShareLinkInput $input): MintedShareLink;

    /**
     * Change expiry, use budget, password or audience of a live link. The token and
     * the role stay; use rotate() for a new token.
     */
    public function update(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ShareLinkUpdate $changes): ShareLinkSnapshot;

    /**
     * Replace the link's token. The old token stops working immediately; the new one
     * is in the result and nowhere else.
     */
    public function rotate(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share): MintedShareLink;

    public function revoke(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share): void;
}
