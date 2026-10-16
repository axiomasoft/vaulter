<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class TrashNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public ?int $expectedSequence = null) {}
}
