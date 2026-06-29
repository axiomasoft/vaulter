<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

/**
 * A live sibling already owns this name under the destination parent (§2.2
 * sibling-unique). Drive operations never silently auto-rename — the conflict is
 * surfaced deterministically so the caller decides (overwrite, suffix, abort).
 */
final class NodeNameConflictException extends VaulterException
{
    public static function forName(string $name, int|string|null $parentId): self
    {
        $where = $parentId === null ? 'the storage root' : "parent [{$parentId}]";

        return new self(
            "[Vaulter] A node named [{$name}] already exists under {$where}. ".
            'Fix: pick a different name or move/trash the existing sibling first. '.
            'Docs: '.self::docs('node-name-conflict'),
        );
    }
}
