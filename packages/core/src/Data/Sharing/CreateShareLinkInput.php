<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Vaulter\Models\Drive\Node;

/** @internal */
final readonly class CreateShareLinkInput
{
    public function __construct(public Node $node, public ShareLinkInput $link) {}
}
