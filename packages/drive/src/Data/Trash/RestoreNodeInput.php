<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

#[Api]
final readonly class RestoreNodeInput
{
    #[Internal]
    public function __construct(public Node $node, public Node $destination, public OnNameConflict $onConflict = OnNameConflict::Rename, public ?int $expectedSequence = null) {}
}
