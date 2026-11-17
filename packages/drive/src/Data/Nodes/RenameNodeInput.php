<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;

#[Internal]
final readonly class RenameNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public string $name, public ?int $expectedSequence = null) {}
}
