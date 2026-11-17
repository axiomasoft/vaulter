<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;

#[Internal]
final readonly class TrashNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public ?int $expectedSequence = null) {}
}
