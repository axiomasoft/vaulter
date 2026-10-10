<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A move/copy targeted a parent in a DIFFERENT storage tree (§2.2 — drive_id
 * is the tree boundary). A single parent_id UPDATE cannot cross storages without
 * re-homing the whole subtree, so the operation is rejected explicitly rather
 * than corrupting the tree.
 */
final class CrossDriveOperationException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'cross_drive_operation';

    private const int HTTP_STATUS = 409;

    public static function between(int|string $fromStorageId, int|string $toStorageId): self
    {
        return new self(
            "[Vaulter] Cannot move a node from storage [{$fromStorageId}] into storage [{$toStorageId}]. ".
            'Fix: a move stays within one storage tree — copy across storages or re-home explicitly instead. '.
            'Docs: '.self::docs('cross-storage-move'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
