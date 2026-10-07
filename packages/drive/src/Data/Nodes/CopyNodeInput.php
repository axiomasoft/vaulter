<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

final readonly class CopyNodeInput
{
    public function __construct(public Node $node, public ?Node $into = null, public ?string $name = null, public OnNameConflict $onConflict = OnNameConflict::Rename) {}
}
