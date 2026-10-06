<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

/** @internal */
final readonly class RevokeGrantInput
{
    public function __construct(public Node $node, public NodeGrant $grant) {}
}
