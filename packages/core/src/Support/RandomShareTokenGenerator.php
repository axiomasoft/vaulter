<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Support\Str;
use Vaulter\Contracts\ShareTokenGenerator;

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
