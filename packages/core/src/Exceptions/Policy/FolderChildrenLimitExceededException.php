<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Policy;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/** A folder already holds the drive policy's maxFolderChildren nodes; trashed ones count until purged. */
final class FolderChildrenLimitExceededException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function forParent(int|string $parentId, int $limit): self
    {
        return new self('[Vaulter] Folder ['.Identity::stringify($parentId).'] already holds '.$limit.' nodes. '
            .'Why: the drive policy limits the children of a folder, trashed ones included until purged. '
            .'Fix: use another folder or purge its trash. Docs: '.self::docs('folder-children-limit-exceeded'), $limit);
    }

    public function problemCode(): string
    {
        return 'folder_children_limit_exceeded';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['limit' => $this->limit];
    }
}
