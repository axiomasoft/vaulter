<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Access;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Node;

/** @internal */
#[Internal]
final readonly class RevokeGrantInput
{
    /** @param int|null $expectedAccessVersion the node's access_version the caller read (If-Match); null skips the check */
    public function __construct(public Node $node, public NodeGrant $grant, public ?int $expectedAccessVersion = null) {}
}
