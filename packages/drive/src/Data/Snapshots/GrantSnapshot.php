<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\GrantScope;

/** Read-only view of a per-node grant. */
#[Api]
final readonly class GrantSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $nodeId,
        public Subject $subject,
        public string $role,
        public int $roleVersion,
        public GrantScope $scope,
        public ?DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public ?string $grantedBy,
    ) {}
}
