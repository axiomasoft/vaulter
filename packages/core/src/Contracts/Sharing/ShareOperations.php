<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Sharing;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

interface ShareOperations
{
    /** @return Collection<int, NodeShare> */
    public function list(Node|int|string $node): Collection;

    public function create(Node|int|string $node, ShareLinkInput $input): MintedShareLink;

    public function revoke(Node|int|string $node, NodeShare|int|string $share): void;
}
