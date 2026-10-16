<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Vaulter\Attributes\Internal;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

/** @internal */
#[Internal]
final readonly class RevokeGrantInput
{
    public function __construct(public Node $node, public NodeGrant $grant) {}
}
