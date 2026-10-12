<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Vaulter\Models\Sharing\NodeShare;

/**
 * The result of minting a bearer share-link — the ONLY place the raw
 * token is ever surfaced. The persisted NodeShare stores only the token's sha256
 * hash + prefix; this DTO carries the raw token alongside it so the creating
 * controller can return it exactly once. It is never persisted and never
 * serialized by any Resource.
 *
 * @internal The public result is {@see \Vaulter\Data\Snapshots\MintedShareLink}.
 */
final readonly class MintedShareLink
{
    public function __construct(
        public NodeShare $share,
        public string $token,
    ) {}
}
