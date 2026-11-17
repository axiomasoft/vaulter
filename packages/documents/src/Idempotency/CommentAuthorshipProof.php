<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use Packman\Attributes\Internal;

/**
 * Whether the attempt of one keyed comment write proved its right by authorship. The {@see CommentReplayAuthorizer} is
 * readonly and built before the attempt runs; the attempt marks the proof here, and a cacheable refusal stored after
 * it records `node-author` as its scope instead of `node`.
 */
#[Internal]
final class CommentAuthorshipProof
{
    public bool $proven = false;
}
