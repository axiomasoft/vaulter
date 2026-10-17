<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Sharing\NodeShare;

/**
 * The result of minting a bearer share-link — the ONLY place the raw
 * token is ever surfaced. The persisted NodeShare stores only the token's sha256
 * hash + prefix; this DTO carries the raw token alongside it so the creating
 * controller can return it exactly once. It is never persisted and never
 * serialized by any Resource.
 *
 * The public result is {@see \Vaulter\Drive\Data\Snapshots\MintedShareLink}.
 */
#[Internal]
final readonly class MintedShareLink
{
    #[Internal]
    public function __construct(
        public NodeShare $share,
        public string $token,
    ) {}
}
