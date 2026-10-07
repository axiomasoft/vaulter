<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Vaulter\Models\Drive\Node;

final readonly class PurgeNodeInput
{
    public function __construct(public Node $node, public ?int $expectedSequence = null) {}
}
