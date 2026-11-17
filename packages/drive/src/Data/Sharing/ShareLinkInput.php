<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Sharing;

use Illuminate\Support\Carbon;
use Packman\Attributes\Api;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;

/**
 * The command DTO for minting a bearer share-link on a node. Carries
 * the role the link grants (previewer, reader, commenter; writer only for an
 * authenticated or tenant audience), who may open it, the reach (item|subtree), an
 * optional plaintext password (hashed by the Action — never stored raw), an
 * optional expiry and an optional use budget. Built from a validated request and
 * consumed ONLY by {@see CreateShareLinkAction}.
 *
 * The raw bearer token is NOT part of this command — it is minted inside the Action
 * and returned once via the result; only its hash + prefix are persisted.
 */
#[Api]
final readonly class ShareLinkInput
{
    public function __construct(
        public string $role = RoleRegistry::READER,
        public ShareAudience $audience = ShareAudience::Anyone,
        public GrantScope $scope = GrantScope::Item,
        public ?Carbon $expiresAt = null,
        public ?string $password = null,
        public ?int $maxUses = null,
    ) {}
}
