<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Surface the conflict as a clean 422 in the same validation-error envelope a
     * FormRequest produces, keyed on `name` — the client treats a sibling clash
     * exactly like an invalid name.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors' => [
                'name' => [$this->getMessage()],
            ],
        ], 422);
    }
}
