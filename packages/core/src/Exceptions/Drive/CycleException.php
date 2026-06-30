<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Drive;

use Vaulter\Exceptions\VaulterException;

/**
 * A move would place a node inside itself or one of its own descendants (§2.13 /
 * H.4 cycle guard), which would orphan a cycle out of the adjacency tree. The
 * guard checks the destination against the moved node's subtree before the single
 * parent_id UPDATE.
 */
final class CycleException extends VaulterException
{
    public static function forMove(int|string $nodeId, int|string $intoId): self
    {
        return new self(
            "[Vaulter] Cannot move node [{$nodeId}] into [{$intoId}] — the destination is the node itself or one of ".
            'its descendants, which would create a cycle. '.
            'Fix: choose a destination outside the moved node\'s own subtree. '.
            'Docs: '.self::docs('move-cycle'),
        );
    }
}
