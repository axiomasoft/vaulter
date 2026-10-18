<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;

/**
 * Read-only view of a share link. The token is never part of it. `version` rises
 * with each update, rotation and revocation: pass it back as the expected version.
 */
#[Api]
final readonly class ShareLinkSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $nodeId,
        public string $role,
        public int $roleVersion,
        public ShareAudience $audience,
        public GrantScope $scope,
        public ?DateTimeImmutable $expiresAt,
        public bool $passwordProtected,
        public ?int $maxUses,
        public int $useCount,
        public string $tokenPrefix,
        public int $tokenGeneration,
        public ?DateTimeImmutable $revokedAt,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
        public int $version = 0,
    ) {}
}
