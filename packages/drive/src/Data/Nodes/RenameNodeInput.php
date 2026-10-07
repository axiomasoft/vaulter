<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Models\Drive\Node;

final readonly class RenameNodeInput
{
    public function __construct(public Node $node, public string $name, public ?int $expectedSequence = null) {}
}
