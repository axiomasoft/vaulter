<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Node;

/** @internal */
#[Internal]
final readonly class CreateShareLinkInput
{
    public function __construct(public Node $node, public ShareLinkInput $link) {}
}
