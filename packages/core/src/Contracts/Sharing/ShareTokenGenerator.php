<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Sharing;

/**
 * Generates the opaque token for a public share link. Swap via
 * config('vaulter.share_token_generator') to produce short, hashed or prefixed
 * tokens. A token must be unique and fit the token column (<= 64 chars).
 */
interface ShareTokenGenerator
{
    public function generate(): string;
}
