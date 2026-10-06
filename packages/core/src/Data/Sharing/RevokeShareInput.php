<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

/** @internal */
final readonly class RevokeShareInput
{
    public function __construct(public Node $node, public NodeShare $share) {}
}
