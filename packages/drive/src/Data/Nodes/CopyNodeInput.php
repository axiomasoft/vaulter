<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Models\Node;

#[Api]
final readonly class CopyNodeInput
{
    /** @param list<string>|null $authorizedNodeIds subtree keys the Operations layer authorised; null = no ACL (system actor) */
    #[Internal]
    public function __construct(public Node $node, public ?Node $into = null, public ?string $name = null, public OnNameConflict $onConflict = OnNameConflict::Rename, public ?array $authorizedNodeIds = null) {}
}
