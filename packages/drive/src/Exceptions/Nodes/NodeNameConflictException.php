<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A live sibling already owns this name under the destination parent (
 * sibling-unique). Drive operations never silently auto-rename — the conflict is
 * surfaced deterministically so the caller decides (overwrite, suffix, abort).
 */
#[Api]
final class NodeNameConflictException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'node_name_conflict';

    private const int HTTP_STATUS = 409;

    public static function forName(string $name, int|string|null $parentId): self
    {
        $safeName = self::boundedDetail($name, 256);
        $where = $parentId === null ? 'the storage root' : 'parent ['.self::boundedDetail((string) $parentId, 128).']';

        return new self(
            "[Vaulter] A node named [{$safeName}] already exists under {$where}. ".
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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::NodeNameConflict,
            category: ErrorCategory::Conflict,
            path: '/name',
            details: ['errors' => [self::boundedDetail($this->getMessage())]],
        );
    }
}
