<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Vaulter\Exceptions\VaulterException;

/**
 * A move/copy targeted a parent in a DIFFERENT storage tree (§2.2 — storage_id
 * is the tree boundary). A single parent_id UPDATE cannot cross storages without
 * re-homing the whole subtree, so the operation is rejected explicitly rather
 * than corrupting the tree.
 */
final class CrossStorageMoveException extends VaulterException
{
    public static function between(int|string $fromStorageId, int|string $toStorageId): self
    {
        return new self(
            "[Vaulter] Cannot move a node from storage [{$fromStorageId}] into storage [{$toStorageId}]. ".
            'Fix: a move stays within one storage tree — copy across storages or re-home explicitly instead. '.
            'Docs: '.self::docs('cross-storage-move'),
        );
    }
}
