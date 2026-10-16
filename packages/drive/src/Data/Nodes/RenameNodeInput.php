<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class RenameNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public string $name, public ?int $expectedSequence = null) {}
}
