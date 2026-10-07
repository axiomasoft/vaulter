<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Models\Drive\Node;

final readonly class UpdateNodeInput
{
    public function __construct(public Node $node, public ?bool $pinned = null, public ?int $position = null, public ?int $expectedSequence = null) {}
}
