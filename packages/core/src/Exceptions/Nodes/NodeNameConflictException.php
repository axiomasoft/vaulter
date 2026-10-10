<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A live sibling already owns this name under the destination parent (§2.2
 * sibling-unique). Drive operations never silently auto-rename — the conflict is
 * surfaced deterministically so the caller decides (overwrite, suffix, abort).
 */
final class NodeNameConflictException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'node_name_conflict';

    private const int HTTP_STATUS = 409;

    public static function forName(string $name, int|string|null $parentId): self
    {
        $where = $parentId === null ? 'the storage root' : "parent [{$parentId}]";

        return new self(
            "[Vaulter] A node named [{$name}] already exists under {$where}. ".
            'Fix: pick a different name or move/trash the existing sibling first. '.
            'Docs: '.self::docs('node-name-conflict'),
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

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['errors' => ['name' => [$this->getMessage()]]];
    }
}
