<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Drive\Node;

/**
 * A tree operation targeted a node that cannot take part in it: the destination is
 * not a folder, belongs to another drive, the destination (or the node itself) sits
 * in the trash, or the operation would rename, move, trash, copy or delete the drive
 * root. Drive operations report another drive as `cross_drive_operation` instead.
 * The tree only stays well-formed if every parent is a live folder of the same drive
 * and the root is never an operand.
 */
final class InvalidDestinationException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'invalid_destination';

    private const int HTTP_STATUS = 409;

    public static function notAFolder(Node $destination): self
    {
        $kind = $destination->kind->value;

        return new self(
            "[Vaulter] Node [{$destination->id}] is a {$kind}, not a folder. "
            .'Why: only a folder can hold child nodes. '
            .'Fix: pick a folder (or the drive root) as the destination. '
            .'Docs: '.self::docs('invalid-destination'),
        );
    }

    public static function trashed(Node $node): self
    {
        return new self(
            "[Vaulter] Node [{$node->id}] is in the trash. "
            .'Why: a trashed node can neither hold new children nor be trashed again. '
            .'Fix: restore it first, or pick a live folder as the destination. '
            .'Docs: '.self::docs('invalid-destination'),
        );
    }

    public static function otherDrive(Node $destination, int|string $storageId): self
    {
        return new self(
            "[Vaulter] Node [{$destination->id}] belongs to drive [{$destination->storage_id}], not [{$storageId}]. "
            .'Why: a node can only be placed under a folder of its own drive. '
            .'Fix: pick a folder of the same drive as the destination. '
            .'Docs: '.self::docs('invalid-destination'),
        );
    }

    public static function rootIsImmutable(Node $root, string $operation): self
    {
        return new self(
            "[Vaulter] Cannot {$operation} the drive root [{$root->id}]. "
            .'Why: the root anchors the drive tree and is never an operand of a tree operation. '
            .'Fix: operate on its children instead. '
            .'Docs: '.self::docs('invalid-destination'),
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
