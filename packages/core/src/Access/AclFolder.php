<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Vaulter\Access\ValueObjects\AclMask;
use Vaulter\Enums\Access\GrantLevel;

final class AclFolder
{
    /** @param list<array{allow:int, deny:int, level:?GrantLevel}> $depths */
    public function foldChain(array $depths): AclMask
    {
        $allowed = 0;
        $denied = 0;
        $level = null;
        foreach ($depths as $depth) {
            // Same-depth allow cannot pierce a deny: merge it only after the veto.
            $denied |= $depth['deny'] & ~$allowed;
            $allowed |= $depth['allow'];
            $level = $level === null ? $depth['level'] : ($depth['level'] === null ? $level : $level->max($depth['level']));
        }

        return new AclMask(allowed: $allowed, denied: $denied, level: $level);
    }

    public function applyOwn(AclMask $inherited, int $ownAllow, int $ownDeny, ?GrantLevel $ownLevel): AclMask
    {
        return new AclMask(
            allowed: $ownAllow | $inherited->allowed,
            denied: $ownDeny | ($inherited->denied & ~$ownAllow),
            level: $ownLevel === null ? $inherited->level : ($inherited->level === null ? $ownLevel : $ownLevel->max($inherited->level)),
        );
    }
}
