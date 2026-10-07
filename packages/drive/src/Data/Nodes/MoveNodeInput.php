<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

final readonly class MoveNodeInput
{
    public function __construct(public Node $node, public ?Node $into = null, public ?int $expectedSequence = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {}
}
