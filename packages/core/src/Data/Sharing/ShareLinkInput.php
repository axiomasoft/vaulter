<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;

/**
 * The command DTO for minting a bearer share-link on a node (§2.7 / M2c). Carries
 * the permissions bitmask (default READ), the human-facing level, the reach
 * (item|subtree), an optional plaintext password (hashed by the Action — never
 * stored raw), the require-login / allow-discovery flags, an optional expiry and an
 * optional use budget. Built from a validated request and consumed ONLY by
 * {@see CreateShareLinkAction}.
 *
 * The raw bearer token is NOT part of this command — it is minted inside the Action
 * and returned once via the result; only its hash + prefix are persisted.
 */
final readonly class ShareLinkInput
{
    public function __construct(
        public int $permissions,
        public GrantLevel $level = GrantLevel::Reader,
        public GrantScope $scope = GrantScope::Item,
        public ?string $password = null,
        public bool $requireLogin = false,
        public bool $allowDiscovery = false,
        public ?Carbon $expiresAt = null,
        public ?int $maxUses = null,
        public int|string|null $grantedBy = null,
    ) {}
}
