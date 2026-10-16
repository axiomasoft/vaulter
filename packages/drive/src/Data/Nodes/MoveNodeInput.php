<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class MoveNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public ?Node $into = null, public ?int $expectedSequence = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {}
}
