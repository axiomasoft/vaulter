<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

/** @internal */
#[Internal]
final readonly class UpdateShareLinkInput
{
    public function __construct(public Node $node, public NodeShare $share, public ShareLinkUpdate $changes) {}
}
