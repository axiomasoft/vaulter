<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Access;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

/** @internal */
#[Internal]
final readonly class RevokeGrantInput
{
    public function __construct(public Node $node, public NodeGrant $grant) {}
}
