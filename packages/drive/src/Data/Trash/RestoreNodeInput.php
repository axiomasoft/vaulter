<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

final readonly class RestoreNodeInput
{
    public function __construct(public Node $node, public Node $destination, public OnNameConflict $onConflict = OnNameConflict::Rename) {}
}
