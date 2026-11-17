<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Sharing;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;

/** @internal */
#[Internal]
final readonly class CreateShareLinkInput
{
    public function __construct(public Node $node, public ShareLinkInput $link) {}
}
