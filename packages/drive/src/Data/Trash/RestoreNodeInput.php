<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Models\Node;

#[Internal]
final readonly class RestoreNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public Node $destination, public OnNameConflict $onConflict = OnNameConflict::Rename, public ?int $expectedSequence = null) {}
}
