<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Sharing;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Generates the opaque token for a public share link. Swap via
 * configuration key vaulter.sharing.token_generator to produce short, hashed or prefixed
 * tokens. A token must be unique and fit the token column (<= 64 chars).
 */
#[Spi(SpiKind::Implement)]
interface ShareTokenGenerator
{
    public function generate(): string;
}
