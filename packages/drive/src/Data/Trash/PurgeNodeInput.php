<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Trash;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Node;

#[Api]
final readonly class PurgeNodeInput
{
    /**
     * @param bool $bypassTrashRequirement Purge a live node: only the system 'drive-purge'
     *                                     of a drive being deleted may ask for it
     */
    #[Internal]
    public function __construct(public Node $node, public ?int $expectedSequence = null, public bool $bypassTrashRequirement = false) {}
}
