<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Documents;

use Vaulter\Attributes\Internal;

/**
 * Why the working state of a document is being written. The cause decides whether
 * an identical write is a no-op, whether the collaboration epoch moves and whether
 * a checkpoint is forced.
 */
#[Internal]
enum StateWriteCause: string
{
    case Update = 'update';
    case Collaboration = 'collaboration';
    case Restore = 'restore';
    case Copy = 'copy';
    case Create = 'create';
    case Seed = 'seed';

    /** Editor saves repeat themselves; an unchanged payload must not count as a change. */
    public function skipsIdenticalWrite(): bool
    {
        return $this === self::Update || $this === self::Collaboration;
    }
}
