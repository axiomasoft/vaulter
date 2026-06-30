<?php

declare(strict_types=1);

namespace Vaulter\Enums\Access;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The reach of a node grant (master plan §2.6). An item grant applies only to the
 * node it sits on; a subtree grant additionally flows down to descendants during
 * the ancestor walk (until an inherit_grants=false cut-off node, H.2 step 2).
 *
 * The stored column value is item|subtree (§2.6), defaulting to subtree.
 */
enum GrantScope: string
{
    use HasLabel;

    #[Label('This item only')]
    case Item = 'item';

    #[Label('This item and everything in it')]
    case Subtree = 'subtree';

    /**
     * Does a grant with this scope, sitting on an ANCESTOR, flow down to a
     * descendant node? Only subtree grants inherit downward.
     */
    public function inheritsDownward(): bool
    {
        return $this === self::Subtree;
    }
}
