<?php

declare(strict_types=1);

namespace DocVault\Support;

use DocVault\Contracts\ShareTokenGenerator;
use Illuminate\Support\Str;

/**
 * Default share-token generator: a 48-character random alphanumeric string.
 */
final class RandomShareTokenGenerator implements ShareTokenGenerator
{
    public function generate(): string
    {
        return Str::random(48);
    }
}
